<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Invoice;
use App\Models\LegacyInvoiceImport;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Support\FeeSchedule;
use App\Support\InvoiceImportColumns;
use App\Support\SpreadsheetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InvoiceImportService
{
    public function __construct(
        private InvoiceService $invoices,
        private ApplicationAdmissionService $admissions,
        private AuditWriter $audit,
    ) {}

    public function template(): StreamedResponse
    {
        return SpreadsheetImport::templateDownload(
            'Invoices',
            InvoiceImportColumns::all(),
            [
                'Import invoices — keyed by matric_number, application_number, or jamb_registration',
                '',
                '1. Import this sheet before Import students / Import applicants if the account does not exist yet. Rows stay pending until a matching record is created.',
                '2. Keep the header row. One row is one invoice. Extra rows with the same invoice_number add extra payments.',
                '3. Identify the payer with at least one of: matric_number, application_number, jamb_registration. Application fee is often paid with APP or JAMB before a matric exists.',
                '4. category must match the fee catalogue (application_fee, tuition, acceptance_fee, hostel, sundry, library, medical, …).',
                '5. Tuition rows require installment_percent: 25, 50, 75, or 100.',
                '6. paid_amount is recorded on the invoice. It does not credit the wallet. Use Import wallet history for wallet credits/debits.',
                '7. If paid_amount is greater than 0, payment_date and payment_method are required. Method: legacy_import, bank_transfer, cash, pos, paystack.',
                '8. Duplicate payment_reference values are skipped.',
                '',
                'Required columns: '.implode(', ', InvoiceImportColumns::required()).'. Also require at least one identifier: matric_number, application_number, or jamb_registration.',
            ],
            InvoiceImportColumns::sample(),
            'invoice-import-template.xlsx',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function import(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path || ! is_readable($path)) {
            throw new \InvalidArgumentException('Unable to read the uploaded file.');
        }

        $rows = SpreadsheetImport::readRows($path, 'Invoices');
        if (count($rows) < 2) {
            throw new \InvalidArgumentException('File must include a header row and at least one data row.');
        }

        $headers = array_map(fn ($value) => SpreadsheetImport::normalizeHeader((string) $value), $rows[0]);
        $indexes = SpreadsheetImport::indexHeaders($headers);
        $posted = 0;
        $pending = 0;
        $skipped = 0;
        $errors = [];

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $line = $i + 1;
            if (! is_array($row) || SpreadsheetImport::rowEmpty($row)) {
                continue;
            }
            $data = SpreadsheetImport::mapRow($row, $indexes);
            try {
                $result = $this->importRow($data, $line);
                if ($result === 'posted') {
                    $posted++;
                } else {
                    $pending++;
                }
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = [
                    'row' => $line,
                    'matric_number' => $this->rowLookup($data),
                    'invoice_number' => $data['invoice_number'] ?? '',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $summary = [
            'posted' => $posted,
            'pending' => $pending,
            'skipped' => $skipped,
            'errors' => $errors,
            'pending_by_matric' => $this->pendingByMatric(),
        ];
        $this->audit->record(
            'invoices.imported',
            "Imported invoices: {$posted} posted, {$pending} pending, {$skipped} skipped",
            'finance',
        );

        return $summary;
    }

    public function countDataRows(UploadedFile|string $file): int
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path) {
            return 0;
        }

        return SpreadsheetImport::countDataRows($path, 'Invoices');
    }

    public function shouldQueue(int $rowCount): bool
    {
        return SpreadsheetImport::shouldQueue($rowCount);
    }

    public function storeUpload(UploadedFile $file): string
    {
        return SpreadsheetImport::storeUpload($file, 'invoice-imports');
    }

    public function cacheResult(string $importId, array $result): void
    {
        SpreadsheetImport::cacheResult($this->cacheKey($importId), $result);
    }

    public function cachedResult(string $importId): ?array
    {
        return SpreadsheetImport::cachedResult($this->cacheKey($importId));
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     */
    public function errorSpreadsheet(array $errors): StreamedResponse
    {
        return SpreadsheetImport::errorSpreadsheet(
            $errors,
            ['row', 'matric_number', 'invoice_number', 'message'],
            'invoice-import-errors.xlsx',
        );
    }

    /**
     * @return list<array{matric_number: string, rows: int}>
     */
    public function pendingByMatric(): array
    {
        return LegacyInvoiceImport::query()
            ->where('status', 'pending')
            ->selectRaw('matric_number, COUNT(*) as rows')
            ->groupBy('matric_number')
            ->orderBy('matric_number')
            ->get()
            ->map(fn ($row) => [
                'matric_number' => $row->matric_number,
                'rows' => (int) $row->rows,
            ])
            ->all();
    }

    /**
     * @return array{posted: int, failed: int}
     */
    public function postPendingForMatric(Student $student): array
    {
        return $this->postPendingForStudent($student);
    }

    /**
     * @return array{posted: int, failed: int}
     */
    public function postPendingForStudent(Student $student): array
    {
        $student->loadMissing(['user', 'application']);
        if (! $student->user) {
            return ['posted' => 0, 'failed' => 0];
        }

        $this->attachOpenInvoicesToStudent($student);

        return $this->postPendingForKeys(
            $this->accountKeys(
                $student->matric_number,
                $student->application?->application_number,
                $student->user?->jamb_registration ?? $student->application?->jamb_registration,
            ),
            $student->user,
            $student->application,
            $student,
        );
    }

    /**
     * @return array{posted: int, failed: int}
     */
    public function postPendingForApplication(Application $application): array
    {
        $application->loadMissing(['user', 'student']);
        if (! $application->user) {
            return ['posted' => 0, 'failed' => 0];
        }

        if ($application->student) {
            $this->attachOpenInvoicesToStudent($application->student);
        }

        return $this->postPendingForKeys(
            $this->accountKeys(
                $application->student?->matric_number,
                $application->application_number,
                $application->user?->jamb_registration ?? $application->jamb_registration,
            ),
            $application->user,
            $application,
            $application->student,
        );
    }

    /**
     * @param  list<string>  $keys
     * @return array{posted: int, failed: int}
     */
    private function postPendingForKeys(array $keys, User $user, ?Application $application, ?Student $student): array
    {
        if ($keys === []) {
            return ['posted' => 0, 'failed' => 0];
        }

        $rows = LegacyInvoiceImport::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->get()
            ->filter(fn (LegacyInvoiceImport $row) => $this->stagingMatchesKeys($row, $keys));

        $posted = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                DB::transaction(fn () => $this->postStaging($row, $user, $application, $student));
                $posted++;
            } catch (Throwable $e) {
                $row->update([
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return ['posted' => $posted, 'failed' => $failed];
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importRow(array $data, int $line): string
    {
        return DB::transaction(function () use ($data, $line) {
            $payload = $this->validatedPayload($data);
            [$user, $application, $student] = $this->resolveAccount($payload);
            $staging = LegacyInvoiceImport::query()->create([
                'matric_number' => $payload['lookup_key'],
                'invoice_number' => $payload['invoice_number'] ?: null,
                'payload' => $payload,
                'status' => 'pending',
                'source_row' => $line,
            ]);

            if (! $user) {
                return 'pending';
            }

            $this->postStaging($staging, $user, $application, $student);

            return 'posted';
        });
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function validatedPayload(array $data): array
    {
        foreach (InvoiceImportColumns::required() as $field) {
            if (blank($data[$field] ?? null)) {
                throw new RuntimeException("Missing required field: {$field}.");
            }
        }

        $matric = $this->normalizeKey((string) ($data['matric_number'] ?? ''));
        $applicationNumber = $this->normalizeKey((string) ($data['application_number'] ?? ''));
        $jamb = $this->normalizeKey((string) ($data['jamb_registration'] ?? ''));
        if ($matric === '' && $applicationNumber === '' && $jamb === '') {
            throw new RuntimeException('Provide matric_number, application_number, or jamb_registration.');
        }

        $category = strtolower(trim((string) $data['category']));
        if (! in_array($category, FeeSchedule::categories(), true)) {
            throw new RuntimeException("Unknown fee category: {$category}.");
        }

        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new RuntimeException('amount must be greater than zero.');
        }

        $full = filled($data['full_amount'] ?? null) ? round((float) $data['full_amount'], 2) : $amount;
        if ($full < $amount) {
            throw new RuntimeException('full_amount cannot be less than amount.');
        }

        $percent = null;
        if ($category === 'tuition') {
            if (blank($data['installment_percent'] ?? null)) {
                throw new RuntimeException('installment_percent is required for tuition invoices.');
            }
            $percent = (int) $data['installment_percent'];
            if (! in_array($percent, FeeSchedule::INSTALLMENT_PERCENTS, true)) {
                throw new RuntimeException('installment_percent must be 25, 50, 75, or 100.');
            }
        } elseif (filled($data['installment_percent'] ?? null)) {
            $percent = (int) $data['installment_percent'];
        }

        $semester = strtolower(trim((string) ($data['semester'] ?? '')));
        if ($semester !== '' && ! in_array($semester, FeeSchedule::SEMESTERS, true)) {
            throw new RuntimeException('semester must be first, second, or both.');
        }

        $paid = filled($data['paid_amount'] ?? null) ? round((float) $data['paid_amount'], 2) : 0.0;
        if ($paid < 0) {
            throw new RuntimeException('paid_amount cannot be negative.');
        }
        if ($paid > $amount) {
            throw new RuntimeException('paid_amount cannot exceed amount.');
        }

        $method = strtolower(trim((string) ($data['payment_method'] ?? '')));
        $paymentDate = SpreadsheetImport::parseDate($data['payment_date'] ?? null);
        if ($paid > 0) {
            if (! $paymentDate) {
                throw new RuntimeException('payment_date is required when paid_amount is set.');
            }
            if ($method === '') {
                $method = 'legacy_import';
            }
            if (! in_array($method, InvoiceImportColumns::PAYMENT_METHODS, true)) {
                throw new RuntimeException('Unknown payment_method.');
            }
        }

        $reference = trim((string) ($data['payment_reference'] ?? ''));
        if ($reference !== '' && Payment::query()->where('reference', $reference)->exists()) {
            throw new RuntimeException('This payment_reference already exists.');
        }

        $lookup = $matric !== '' ? $matric : ($applicationNumber !== '' ? $applicationNumber : $jamb);

        return [
            'lookup_key' => $lookup,
            'matric_number' => $matric,
            'application_number' => $applicationNumber,
            'jamb_registration' => $jamb,
            'invoice_number' => strtoupper(trim((string) ($data['invoice_number'] ?? ''))),
            'category' => $category,
            'session_label' => trim((string) ($data['session_label'] ?? '')),
            'semester' => $semester,
            'installment_percent' => $percent,
            'amount' => $amount,
            'full_amount' => $full,
            'description' => trim((string) ($data['description'] ?? '')),
            'paid_amount' => $paid,
            'payment_date' => $paymentDate,
            'payment_method' => $method !== '' ? $method : 'legacy_import',
            'payment_reference' => $reference,
        ];
    }

    private function postStaging(
        LegacyInvoiceImport $staging,
        User $user,
        ?Application $application,
        ?Student $student,
    ): void {
        $payload = is_array($staging->payload) ? $staging->payload : [];
        $number = trim((string) ($payload['invoice_number'] ?? ''));
        $paid = (float) ($payload['paid_amount'] ?? 0);
        $invoice = $number !== ''
            ? Invoice::query()->where('number', $number)->first()
            : null;

        if ($invoice) {
            if ($paid <= 0) {
                throw new RuntimeException("Invoice {$number} already exists; add a paid_amount to record an extra payment.");
            }
            $this->applyImportedPayment($user, $invoice, $payload, $application, $student);
            $staging->update([
                'status' => 'posted',
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'error_message' => null,
            ]);

            return;
        }

        $application = $application ?? $student?->application;
        $description = $this->description($payload);
        $invoice = $this->invoices->createForCharge(
            $user,
            (string) $payload['category'],
            (float) $payload['amount'],
            $description,
            $application?->id ?? $student?->application_id,
            $student?->id,
        );
        $number = $number !== '' ? $number : $this->nextLegacyNumber((string) ($payload['session_label'] ?? ''));
        $invoice->update([
            'number' => $number,
            'installment_percent' => $payload['installment_percent'] ?? null,
            'full_amount' => $payload['full_amount'] ?? $payload['amount'],
            'wallet_allowed' => FeeSchedule::walletAllowed((string) $payload['category']),
        ]);
        $invoice = $invoice->fresh();
        $this->linkAdmissionInvoice($invoice, $application);

        if ($paid > 0) {
            $this->applyImportedPayment($user, $invoice, $payload, $application, $student);
        }

        $staging->update([
            'status' => 'posted',
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'error_message' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyImportedPayment(
        User $user,
        Invoice $invoice,
        array $payload,
        ?Application $application,
        ?Student $student,
    ): void {
        $paid = (float) ($payload['paid_amount'] ?? 0);
        if ($paid <= 0) {
            return;
        }
        $reference = trim((string) ($payload['payment_reference'] ?? ''));
        if ($reference !== '' && Payment::query()->where('reference', $reference)->exists()) {
            throw new RuntimeException('This payment_reference already exists.');
        }

        $this->invoices->applyPayment($invoice, $paid);
        $payment = $invoice->payments()->create([
            'user_id' => $user->id,
            'method' => $payload['payment_method'] ?: 'legacy_import',
            'amount' => $paid,
            'status' => 'successful',
            'reference' => $reference !== '' ? $reference : 'LEG-'.Str::upper(Str::random(10)),
            'receipt_no' => 'RCP-'.Str::upper(Str::random(6)),
            'purpose' => $invoice->category,
        ]);
        if (! empty($payload['payment_date'])) {
            $payment->created_at = $payload['payment_date'].' 00:00:00';
            $payment->save();
        }

        $invoice = $invoice->fresh();
        $this->linkAdmissionInvoice($invoice, $application ?? $student?->application);
        if ($invoice->status === 'paid' && $invoice->category === 'application_fee') {
            $this->admissions->handleInvoicePaid($invoice);
        }
    }

    private function linkAdmissionInvoice(Invoice $invoice, ?Application $application): void
    {
        if (! $application) {
            return;
        }

        $updates = [];
        if (! $invoice->application_id) {
            $updates['application_id'] = $application->id;
        }
        if ($updates !== []) {
            $invoice->update($updates);
            $invoice->refresh();
        }

        if ($invoice->category === 'application_fee' && ! $application->application_fee_invoice_id) {
            $application->update(['application_fee_invoice_id' => $invoice->id]);
        }
        if ($invoice->category === 'acceptance_fee' && ! $application->acceptance_fee_invoice_id) {
            $application->update(['acceptance_fee_invoice_id' => $invoice->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?User, 1: ?Application, 2: ?Student}
     */
    private function resolveAccount(array $payload): array
    {
        $matric = (string) ($payload['matric_number'] ?? '');
        $applicationNumber = (string) ($payload['application_number'] ?? '');
        $jamb = (string) ($payload['jamb_registration'] ?? '');

        $student = null;
        $application = null;
        $user = null;

        if ($matric !== '') {
            $student = Student::query()
                ->whereRaw('UPPER(REPLACE(COALESCE(matric_number, ""), " ", "")) = ?', [$matric])
                ->first();
        }

        if ($applicationNumber !== '') {
            $application = Application::query()
                ->whereRaw('UPPER(REPLACE(COALESCE(application_number, ""), " ", "")) = ?', [$applicationNumber])
                ->first();
            if ($application && ! $student) {
                $student = $application->student
                    ?? Student::query()->where('application_id', $application->id)->first();
            }
        }

        if ($jamb !== '') {
            $user = User::query()->where('jamb_registration', $jamb)->first();
            if (! $application) {
                $application = Application::query()
                    ->whereRaw('UPPER(REPLACE(COALESCE(jamb_registration, ""), " ", "")) = ?', [$jamb])
                    ->latest('id')
                    ->first();
                if (! $application && $user) {
                    $application = $user->latestApplication;
                }
            }
            if (! $student) {
                $student = $user?->student ?? $application?->student;
            }
        }

        if ($student) {
            $student->loadMissing(['user', 'application']);

            return [$student->user, $student->application ?? $application, $student];
        }

        if ($application) {
            $application->loadMissing(['user', 'student']);

            return [$application->user, $application, $application->student];
        }

        if ($user) {
            $user->loadMissing(['student', 'latestApplication']);

            return [$user, $user->latestApplication, $user->student];
        }

        return [null, null, null];
    }

    /**
     * @param  list<string>  $keys
     */
    private function stagingMatchesKeys(LegacyInvoiceImport $row, array $keys): bool
    {
        if (in_array($row->matric_number, $keys, true)) {
            return true;
        }

        $payload = is_array($row->payload) ? $row->payload : [];
        foreach (['lookup_key', 'matric_number', 'application_number', 'jamb_registration'] as $field) {
            $value = $this->normalizeKey((string) ($payload[$field] ?? ''));
            if ($value !== '' && in_array($value, $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function accountKeys(?string $matric, ?string $applicationNumber, ?string $jamb): array
    {
        return array_values(array_unique(array_filter([
            $this->normalizeKey((string) $matric),
            $this->normalizeKey((string) $applicationNumber),
            $this->normalizeKey((string) $jamb),
        ])));
    }

    private function attachOpenInvoicesToStudent(Student $student): void
    {
        if (! $student->user_id) {
            return;
        }

        Invoice::query()
            ->where('user_id', $student->user_id)
            ->whereNull('student_id')
            ->update(['student_id' => $student->id]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rowLookup(array $data): string
    {
        foreach (['matric_number', 'application_number', 'jamb_registration'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function description(array $payload): string
    {
        $parts = array_filter([
            $payload['session_label'] ?? '',
            $payload['semester'] ?? '',
            $payload['description'] ?? '',
            FeeSchedule::label((string) ($payload['category'] ?? '')),
        ]);

        return implode(' · ', $parts) ?: 'Imported invoice';
    }

    private function nextLegacyNumber(string $session): string
    {
        $session = $session !== '' ? str_replace(' ', '', $session) : now()->format('Y');
        $prefix = 'LEG/'.$session.'/';
        $last = Invoice::query()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');
        $sequence = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    private function normalizeKey(string $value): string
    {
        return strtoupper(str_replace(' ', '', trim($value)));
    }

    private function cacheKey(string $importId): string
    {
        return 'invoice-import:'.$importId;
    }
}
