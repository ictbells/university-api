<?php

namespace App\Services;

use App\Models\LegacyWalletImport;
use App\Models\Student;
use App\Models\WalletTransaction;
use App\Support\SpreadsheetImport;
use App\Support\WalletImportColumns;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class WalletImportService
{
    public function __construct(
        private WalletService $wallets,
        private AuditWriter $audit,
    ) {}

    public function template(): StreamedResponse
    {
        return SpreadsheetImport::templateDownload(
            'Wallet',
            WalletImportColumns::all(),
            [
                'Import wallet history — keyed by matric_number',
                '',
                '1. One row is one credit or debit. Replay is in occurred_at order.',
                '2. Import invoices first if you also have billed fees. Wallet credits do not settle invoices.',
                '3. type must be credit or debit. amount must be positive.',
                '4. If a debit would take the wallet below zero, that row and remaining rows for the same matric are skipped.',
                '5. Rows stay pending until the student (and wallet) exist.',
                '6. Duplicate reference values are skipped.',
                '',
                'Required columns: '.implode(', ', WalletImportColumns::required()),
            ],
            WalletImportColumns::sample(),
            'wallet-import-template.xlsx',
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

        $rows = SpreadsheetImport::readRows($path, 'Wallet');
        if (count($rows) < 2) {
            throw new \InvalidArgumentException('File must include a header row and at least one data row.');
        }

        $headers = array_map(fn ($value) => SpreadsheetImport::normalizeHeader((string) $value), $rows[0]);
        $indexes = SpreadsheetImport::indexHeaders($headers);
        $posted = 0;
        $pending = 0;
        $skipped = 0;
        $errors = [];
        $matricsToPost = [];
        $seenRefs = $this->pendingReferences();

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $line = $i + 1;
            if (! is_array($row) || SpreadsheetImport::rowEmpty($row)) {
                continue;
            }
            $data = SpreadsheetImport::mapRow($row, $indexes);
            try {
                $matric = $this->stageRow($data, $line, $seenRefs);
                $student = $this->findStudent($matric);
                if ($student) {
                    $matricsToPost[$matric] = $student;
                    $pending++;
                } else {
                    $pending++;
                }
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = [
                    'row' => $line,
                    'matric_number' => $data['matric_number'] ?? '',
                    'message' => $e->getMessage(),
                ];
            }
        }

        foreach ($matricsToPost as $student) {
            $result = $this->postPendingForMatric($student);
            $posted += $result['posted'];
            $pending -= $result['posted'] + $result['failed'];
            $skipped += $result['failed'];
            foreach ($result['errors'] as $error) {
                $errors[] = $error;
            }
        }

        $summary = [
            'posted' => max(0, $posted),
            'pending' => max(0, $pending),
            'skipped' => $skipped,
            'errors' => $errors,
            'pending_by_matric' => $this->pendingByMatric(),
        ];
        $this->audit->record(
            'wallet.imported',
            "Imported wallet history: {$summary['posted']} posted, {$summary['pending']} pending, {$skipped} skipped",
            'wallet',
        );

        return $summary;
    }

    public function countDataRows(UploadedFile|string $file): int
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path) {
            return 0;
        }

        return SpreadsheetImport::countDataRows($path, 'Wallet');
    }

    public function shouldQueue(int $rowCount): bool
    {
        return SpreadsheetImport::shouldQueue($rowCount);
    }

    public function storeUpload(UploadedFile $file): string
    {
        return SpreadsheetImport::storeUpload($file, 'wallet-imports');
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
            ['row', 'matric_number', 'message'],
            'wallet-import-errors.xlsx',
        );
    }

    /**
     * @return list<array{matric_number: string, rows: int}>
     */
    public function pendingByMatric(): array
    {
        return LegacyWalletImport::query()
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
     * @return array{posted: int, failed: int, errors: list<array<string, mixed>>}
     */
    public function postPendingForMatric(Student $student): array
    {
        $matric = $this->normalizeMatric((string) $student->matric_number);
        $rows = LegacyWalletImport::query()
            ->where('matric_number', $matric)
            ->where('status', 'pending')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $posted = 0;
        $failed = 0;
        $errors = [];
        $halted = false;
        $haltMessage = '';

        foreach ($rows as $row) {
            if ($halted) {
                $row->update([
                    'status' => 'error',
                    'error_message' => $haltMessage,
                ]);
                $failed++;
                $errors[] = [
                    'row' => $row->source_row,
                    'matric_number' => $matric,
                    'message' => $haltMessage,
                ];
                continue;
            }
            try {
                DB::transaction(fn () => $this->postStaging($student, $row));
                $posted++;
            } catch (Throwable $e) {
                $halted = true;
                $haltMessage = $e->getMessage();
                $row->update([
                    'status' => 'error',
                    'error_message' => $haltMessage,
                ]);
                $failed++;
                $errors[] = [
                    'row' => $row->source_row,
                    'matric_number' => $matric,
                    'message' => $haltMessage,
                ];
            }
        }

        return ['posted' => $posted, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * @return array<string, true>
     */
    private function pendingReferences(): array
    {
        $seen = [];
        foreach (LegacyWalletImport::query()->where('status', 'pending')->get(['payload']) as $row) {
            $reference = trim((string) data_get($row->payload, 'reference'));
            if ($reference !== '') {
                $seen[$reference] = true;
            }
        }

        return $seen;
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, true>  $seenRefs
     */
    private function stageRow(array $data, int $line, array &$seenRefs): string
    {
        foreach (WalletImportColumns::required() as $field) {
            if (blank($data[$field] ?? null)) {
                throw new RuntimeException("Missing required field: {$field}.");
            }
        }

        $matric = $this->normalizeMatric((string) $data['matric_number']);
        $type = strtolower(trim((string) $data['type']));
        if (! in_array($type, WalletImportColumns::TYPES, true)) {
            throw new RuntimeException('type must be credit or debit.');
        }
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new RuntimeException('amount must be greater than zero.');
        }
        $occurred = SpreadsheetImport::parseDateTime($data['occurred_at'] ?? null);
        if (! $occurred) {
            throw new RuntimeException('occurred_at must be a valid date or datetime.');
        }
        $reference = trim((string) ($data['reference'] ?? ''));
        if ($reference !== '' && WalletTransaction::query()->where('reference', $reference)->exists()) {
            throw new RuntimeException('This wallet reference already exists.');
        }
        if ($reference !== '' && isset($seenRefs[$reference])) {
            throw new RuntimeException('This wallet reference is already staged.');
        }

        LegacyWalletImport::query()->create([
            'matric_number' => $matric,
            'payload' => [
                'type' => $type,
                'amount' => $amount,
                'occurred_at' => $occurred,
                'description' => trim((string) ($data['description'] ?? '')) ?: 'Legacy wallet '.$type,
                'reference' => $reference,
                'source_module' => trim((string) ($data['source_module'] ?? '')) ?: 'legacy_import',
            ],
            'status' => 'pending',
            'occurred_at' => $occurred,
            'source_row' => $line,
        ]);

        if ($reference !== '') {
            $seenRefs[$reference] = true;
        }

        return $matric;
    }

    private function postStaging(Student $student, LegacyWalletImport $staging): void
    {
        $student->loadMissing('wallet');
        if (! $student->wallet) {
            throw new RuntimeException('This student has no wallet.');
        }
        $payload = is_array($staging->payload) ? $staging->payload : [];
        $type = (string) ($payload['type'] ?? '');
        $amount = (float) ($payload['amount'] ?? 0);
        $description = (string) ($payload['description'] ?? 'Legacy wallet import');
        $module = (string) ($payload['source_module'] ?? 'legacy_import');
        $reference = trim((string) ($payload['reference'] ?? '')) ?: null;

        if ($type === 'credit') {
            $this->wallets->credit($student->wallet, $amount, $description, $module, null, $reference);
        } elseif ($type === 'debit') {
            $this->wallets->debit($student->wallet, $amount, $description, $module, null, $reference);
        } else {
            throw new RuntimeException('Unknown wallet type.');
        }

        $tx = $student->wallet->transactions()->latest('id')->first();
        if ($tx && ! empty($payload['occurred_at'])) {
            $tx->created_at = $payload['occurred_at'];
            $tx->save();
        }

        $staging->update([
            'status' => 'posted',
            'error_message' => null,
        ]);
    }

    private function findStudent(string $matric): ?Student
    {
        return Student::query()
            ->whereRaw('UPPER(REPLACE(COALESCE(matric_number, ""), " ", "")) = ?', [$matric])
            ->first();
    }

    private function normalizeMatric(string $matric): string
    {
        return strtoupper(str_replace(' ', '', trim($matric)));
    }

    private function cacheKey(string $importId): string
    {
        return 'wallet-import:'.$importId;
    }
}
