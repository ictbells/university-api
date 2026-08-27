<?php

namespace App\Http\Controllers;

use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeCategory;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Program;
use App\Models\ProgrammeFee;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AuditWriter;
use App\Services\FeeArrearsService;
use App\Services\InvoiceExportService;
use App\Services\InvoiceService;
use App\Services\StudentFinanceExportService;
use App\Services\UniversityFinanceStatementService;
use App\Support\AdmissionEntryRules;
use App\Support\FeeSchedule;
use App\Support\TranscriptType;
use App\Support\InstitutionLogo;
use App\Support\InvoiceSettlement;
use App\Support\ListSessionLevelFilter;
use App\Support\NairaWords;
use App\Support\ProgrammeFeeResolver;
use App\Support\StudentFinanceStatus;
use App\Support\TuitionProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class FinanceController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
    public function __construct(
        private InvoiceService $invoices,
        private InvoiceExportService $invoiceExports,
        private StudentFinanceExportService $studentFinanceExports,
        private UniversityFinanceStatementService $universityStatement,
        private FeeArrearsService $arrears,
        private AuditWriter $audit,
    ) {}

    public function fees(Request $request)
    {
        $query = FeeItem::query()->orderBy('display_order')->orderBy('category')->orderBy('name');
        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }
        if ($request->filled('category')) {
            $query->where('category', (string) $request->input('category'));
        }
        if ($request->boolean('operational')) {
            $query->whereIn('category', FeeSchedule::staffDirectInvoiceCategories());
        }

        return $query->with('program:id,name,code,study_level')->get();
    }

    public function feeMeta()
    {
        return [
            'categories' => FeeSchedule::staffEditableCategoryOptions(),
            'schedule_categories' => FeeSchedule::scheduleCategories(),
            'installment_percents' => FeeSchedule::INSTALLMENT_PERCENTS,
            'installment_tranches' => FeeSchedule::installmentTrancheOptions(),
            'semesters' => FeeSchedule::SEMESTERS,
            'transcript_types' => TranscriptType::options(),
        ];
    }

    public function feeCategories(Request $request)
    {
        $query = FeeCategory::query()
            ->staffEditable()
            ->orderBy('display_order')
            ->orderBy('name');

        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    public function storeFeeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'is_schedule' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $code = FeeSchedule::codeFromName($data['name']);
        $base = $code;
        $suffix = 2;
        while (FeeCategory::query()->where('code', $code)->exists()) {
            $code = substr($base, 0, 56).'_'.$suffix;
            $suffix++;
        }

        $payload = [
            'code' => $code,
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'is_schedule' => $request->boolean('is_schedule', false),
            'is_system' => false,
            'is_active' => $request->boolean('is_active', true),
            'display_order' => (int) (FeeCategory::query()->max('display_order') ?? 0) + 1,
        ];

        return $this->officeGate('finance.store_fee_category', null, $payload, 'Create fee category', function () use ($payload) {
            $category = FeeCategory::query()->create($payload);
            $this->audit->record('fee_category.created', 'Fee category created', 'fees', 'fee_category', $category->id, null, $category);

            return $category;
        });
    }

    public function updateFeeCategory(Request $request, FeeCategory $feeCategory)
    {
        $before = $feeCategory->toArray();
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:255',
            'is_schedule' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($request->has('is_schedule')) {
            $data['is_schedule'] = $request->boolean('is_schedule');
        }
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $this->officeGate(
            'finance.update_fee_category',
            $feeCategory,
            ['fee_category_id' => $feeCategory->id, ...$data],
            'Update fee category',
            function () use ($feeCategory, $data, $before) {
                $feeCategory->update($data);
                $this->audit->record('fee_category.updated', 'Fee category updated', 'fees', 'fee_category', $feeCategory->id, $before, $feeCategory);

                return $feeCategory->fresh();
            }
        );
    }

    public function destroyFeeCategory(FeeCategory $feeCategory)
    {
        if ($feeCategory->is_system) {
            return response()->json(['message' => 'System fee categories cannot be deleted.'], 422);
        }

        if (FeeItem::query()->where('category', $feeCategory->code)->exists()) {
            return response()->json(['message' => 'Remove or reassign fee catalog items that use this category first.'], 422);
        }

        return $this->officeGate(
            'finance.destroy_fee_category',
            $feeCategory,
            ['fee_category_id' => $feeCategory->id],
            'Delete fee category',
            function () use ($feeCategory) {
                $before = $feeCategory->toArray();
                $feeCategory->delete();
                $this->audit->record('fee_category.deleted', 'Fee category deleted', 'fees', 'fee_category', $feeCategory->id, $before, null);

                return response()->noContent();
            }
        );
    }

    public function storeFee(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'category' => ['required', 'string', Rule::in(FeeSchedule::staffEditableCategories())],
            'entry_mode' => [
                Rule::requiredIf(fn () => FeeSchedule::requiresEntryMode((string) $request->input('category'))),
                'nullable',
                Rule::in(AdmissionEntryRules::ENTRY_MODE_ORDER),
            ],
            'transcript_type' => [
                Rule::requiredIf(fn () => $request->input('category') === 'transcript'),
                'nullable',
                Rule::in(TranscriptType::ALL),
            ],
            'program_id' => [
                Rule::requiredIf(fn () => $request->input('category') === 'transcript'),
                'nullable',
                'integer',
                'exists:programs,id',
            ],
            'installment_tranche' => ['nullable', 'integer', Rule::in(FeeSchedule::INSTALLMENT_TRANCHES)],
            'amount' => 'required|numeric|min:0',
            'is_required' => 'boolean',
            'display_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);
        $data['wallet_allowed'] = FeeSchedule::walletAllowed($data['category']);
        if (! FeeSchedule::requiresEntryMode($data['category'])) {
            $data['entry_mode'] = null;
        }
        if ($data['category'] !== 'transcript') {
            $data['transcript_type'] = null;
            $data['program_id'] = null;
        } else {
            $this->assertUniqueTranscriptFee(
                (string) $data['transcript_type'],
                (int) $data['program_id'],
            );
        }
        if (! FeeSchedule::allowsInstallmentTranche($data['category'])) {
            $data['installment_tranche'] = null;
        } else {
            $data['installment_tranche'] = $data['installment_tranche'] ?? null;
        }
        $data['is_required'] = $request->boolean('is_required', true);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['display_order'] = (int) ($data['display_order'] ?? 0);

        return $this->officeGate('finance.store_fee', null, $data, 'Create fee item', function () use ($data) {
            $fee = FeeItem::query()->create($data);
            $this->audit->record('fee.created', 'Fee item created', 'fees', 'fee_item', $fee->id, null, $fee);

            return $fee;
        });
    }

    public function updateFee(Request $request, FeeItem $fee)
    {
        $before = $fee->toArray();
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:255',
            'category' => ['sometimes', 'string', Rule::in(FeeSchedule::staffEditableCategories())],
            'entry_mode' => [
                Rule::requiredIf(fn () => FeeSchedule::requiresEntryMode((string) ($request->input('category') ?? $fee->category))),
                'nullable',
                Rule::in(AdmissionEntryRules::ENTRY_MODE_ORDER),
            ],
            'transcript_type' => [
                Rule::requiredIf(fn () => ($request->input('category') ?? $fee->category) === 'transcript'),
                'nullable',
                Rule::in(TranscriptType::ALL),
            ],
            'program_id' => [
                Rule::requiredIf(fn () => ($request->input('category') ?? $fee->category) === 'transcript'),
                'nullable',
                'integer',
                'exists:programs,id',
            ],
            'installment_tranche' => ['nullable', 'integer', Rule::in(FeeSchedule::INSTALLMENT_TRANCHES)],
            'amount' => 'sometimes|numeric|min:0',
            'is_required' => 'boolean',
            'display_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);
        $category = $data['category'] ?? $fee->category;
        $data['wallet_allowed'] = FeeSchedule::walletAllowed($category);
        if (! FeeSchedule::requiresEntryMode($category)) {
            $data['entry_mode'] = null;
        }
        if ($category !== 'transcript') {
            $data['transcript_type'] = null;
            $data['program_id'] = null;
        } else {
            $this->assertUniqueTranscriptFee(
                (string) ($data['transcript_type'] ?? $fee->transcript_type),
                (int) ($data['program_id'] ?? $fee->program_id),
                $fee->id,
            );
        }
        if (! FeeSchedule::allowsInstallmentTranche($category)) {
            $data['installment_tranche'] = null;
        } elseif ($request->exists('installment_tranche') && $request->input('installment_tranche') === null) {
            $data['installment_tranche'] = null;
        }
        if ($request->has('is_required')) {
            $data['is_required'] = $request->boolean('is_required');
        }
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        return $this->officeGate('finance.update_fee', $fee, ['fee_id' => $fee->id, ...$data], 'Update fee item', function () use ($fee, $data, $before) {
            $fee->update($data);
            $this->audit->record('fee.updated', 'Fee item updated', 'fees', 'fee_item', $fee->id, $before, $fee);

            return $fee->fresh();
        });
    }

    public function destroyFee(FeeItem $fee)
    {
        return $this->officeGate('finance.destroy_fee', $fee, ['fee_id' => $fee->id], 'Delete fee item', function () use ($fee) {
            $before = $fee->toArray();
            $fee->delete();
            $this->audit->record('fee.deleted', 'Fee item deleted', 'fees', 'fee_item', $fee->id, $before, null);

            return response()->noContent();
        });
    }

    private function assertUniqueTranscriptFee(string $transcriptType, int $programId, ?int $ignoreId = null): void
    {
        $exists = FeeItem::query()
            ->where('category', 'transcript')
            ->where('transcript_type', $transcriptType)
            ->where('program_id', $programId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'transcript_type' => ['A transcript fee already exists for this programme and transcript type.'],
            ]);
        }
    }

    public function invoices(Request $request)
    {
        if ($application = $request->user()?->latestApplication) {
            $this->invoices->ensureAcceptanceInvoiceIfOffered($application);
        }
        if ($student = $request->user()?->student) {
            $this->arrears->ensureForStudent($student);
        }

        $perPage = min(50, max(10, (int) $request->input('per_page', 25)));

        return $this->invoiceListQuery($request)->paginate($perPage);
    }

    public function exportInvoices(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $request->validate([
            'format' => 'required|in:pdf,excel,word',
            'status' => 'nullable|string',
            'category' => 'nullable|string',
            'faculty_id' => 'nullable|integer',
            'college_id' => 'nullable|integer',
            'department_id' => 'nullable|integer',
            'program_id' => 'nullable|integer',
            'academic_session_id' => 'nullable',
            'level' => 'nullable|string',
            'search' => 'nullable|string',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'academic_session_id' => 'nullable',
            'level' => 'nullable|string',
        ]);

        $invoices = $this->invoiceListQuery($request)
            ->with([
                'user:id,name,email,jamb_registration',
                'user.latestApplication',
                'application:id,user_id,application_number,jamb_registration',
                'student.program.department.faculty',
            ])
            ->limit(InvoiceExportService::MAX_ROWS)
            ->get();

        return $this->invoiceExports->export(
            $data['format'],
            $invoices,
            'Invoices report',
            $this->invoiceFilterSummary($request),
        );
    }

    public function disableInvoice(Request $request, Invoice $invoice)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $reason = trim($data['reason']);

        return $this->officeGate('finance.disable_invoice', $invoice, ['invoice_id' => $invoice->id, ...$data], 'Disable invoice', function () use ($invoice, $reason) {
            try {
                $invoice = $this->invoices->disable($invoice, $reason);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $this->audit->record(
                'invoice.disabled',
                'Invoice '.$invoice->number.' disabled',
                'fees',
                'invoice',
                $invoice->id,
                null,
                $invoice,
                $reason,
            );

            return $invoice;
        });
    }

    public function enableInvoice(Request $request, Invoice $invoice)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        return $this->officeGate('finance.enable_invoice', $invoice, ['invoice_id' => $invoice->id], 'Enable invoice', function () use ($invoice) {
            try {
                $invoice = $this->invoices->enable($invoice);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $this->audit->record('invoice.enabled', 'Invoice '.$invoice->number.' enabled', 'fees', 'invoice', $invoice->id, null, $invoice);

            return $invoice;
        });
    }

    public function history(Request $request)
    {
        if ($student = $request->user()?->student) {
            $this->arrears->ensureForStudent($student);
        }

        $userId = $request->user()->id;

        $invoices = Invoice::query()
            ->with([
                'items',
                'academicSession:id,label',
                'payments' => fn ($q) => $q->where('status', 'successful')->latest(),
                'rebates.rebateType',
            ])
            ->where('user_id', $userId)
            ->latest()
            ->limit(200)
            ->get()
            ->map(function (Invoice $invoice) {
                $row = $invoice->toArray();
                $row['kind'] = 'invoice';
                $row['invoice_id'] = $invoice->id;
                $row['payment_id'] = $invoice->payments->first()?->id;
                $row['installment_label'] = $invoice->shareLabel();
                $row['sort_at'] = optional($invoice->created_at)->timestamp ?? 0;

                return $row;
            });

        $topups = Payment::query()
            ->where('user_id', $userId)
            ->where('purpose', 'wallet_topup')
            ->where('status', 'successful')
            ->latest()
            ->limit(200)
            ->get()
            ->map(function (Payment $payment) {
                return [
                    'kind' => 'wallet_topup',
                    'id' => $payment->id,
                    'invoice_id' => null,
                    'payment_id' => $payment->id,
                    'number' => $payment->receipt_no ?: $payment->reference,
                    'category' => 'wallet_funding',
                    'amount' => $payment->amount,
                    'balance' => 0,
                    'status' => 'paid',
                    'receipt_no' => $payment->receipt_no,
                    'reference' => $payment->reference,
                    'created_at' => $payment->created_at,
                    'items' => [],
                    'payments' => [$payment->toArray()],
                    'installment_percent' => null,
                    'sort_at' => optional($payment->created_at)->timestamp ?? 0,
                ];
            });

        $rows = $invoices
            ->concat($topups)
            ->sortByDesc('sort_at')
            ->map(function (array $row) {
                unset($row['sort_at']);

                return $row;
            })
            ->values();

        return ['data' => $rows];
    }

    public function paymentReceipt(Request $request, Payment $payment): Response
    {
        $user = $request->user();
        $canManage = $user->hasPermission('finance.invoices.manage');
        abort_unless($canManage || $payment->user_id === $user->id, 403);
        abort_unless($payment->status === 'successful', 422, 'Receipt is available after payment succeeds.');

        if ($payment->invoice_id) {
            return $this->receipt($request, $payment->invoice ?: Invoice::query()->findOrFail($payment->invoice_id));
        }

        abort_unless($payment->purpose === 'wallet_topup', 422, 'Receipt is not available for this payment.');

        $payment->load(['user', 'user.student']);
        $student = $payment->user?->student;
        $method = $this->paymentMethodLabel($payment->method ?: 'online');
        $amount = (float) $payment->amount;
        $html = view('receipts.wallet', [
            'institution' => $this->receiptInstitution(),
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'doc_title' => 'Wallet funding receipt',
            'receipt_no' => $payment->receipt_no ?: $payment->reference ?: 'RCP-'.$payment->id,
            'payer' => $payment->user?->name ?: '—',
            'payer_id' => $student?->matric_number,
            'payer_id_label' => 'Matric number',
            'category_label' => 'Campus wallet funding',
            'payment_method' => $method,
            'reference' => $payment->reference ?: '—',
            'paid_at' => optional($payment->created_at)->format('d M Y, h:i A') ?: '—',
            'amount' => $amount,
            'amount_words' => NairaWords::phrase($amount),
            'generated_at' => now()->format('d M Y, h:i A'),
        ])->render();

        $filename = 'receipt-'.($payment->receipt_no ?: $payment->reference ?: $payment->id).'.html';
        $headers = ['Content-Type' => 'text/html; charset=UTF-8'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$filename.'"';
        }

        return response($html, 200, $headers);
    }

    public function receipt(Request $request, Invoice $invoice): Response
    {
        $user = $request->user();
        $canManage = $user->hasPermission('finance.invoices.manage');
        abort_unless($canManage || $invoice->user_id === $user->id, 403);
        abort_unless($invoice->status === 'paid', 422, 'Receipt is available after the invoice is paid.');

        $invoice->load([
            'items',
            'user.student',
            'student.program',
            'application',
            'payments' => fn ($q) => $q->where('status', 'successful')->latest(),
        ]);
        $payment = $invoice->payments->first();
        abort_unless($payment, 422, 'No successful payment was found for this invoice.');

        $student = $invoice->student ?: $invoice->user?->student;
        $application = $invoice->application;
        $payerId = $student?->matric_number;
        $payerIdLabel = 'Matric number';
        if (! $payerId) {
            $payerId = $application?->jamb_registration
                ?: $invoice->user?->jamb_registration
                ?: $application?->application_number;
            $payerIdLabel = ($application?->jamb_registration || $invoice->user?->jamb_registration)
                ? 'JAMB number'
                : 'Application number';
        }

        $amount = (float) $payment->amount;
        $category = (string) $invoice->category;
        $installmentPercent = $category === 'tuition' && $invoice->installment_percent !== null
            ? (int) $invoice->installment_percent
            : null;
        $html = view('receipts.invoice', [
            'institution' => $this->receiptInstitution(),
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'doc_title' => 'Official payment receipt',
            'receipt_no' => $payment->receipt_no ?: $invoice->number,
            'payer' => $invoice->user?->name
                ?: trim(implode(' ', array_filter([$student?->first_name, $student?->last_name])))
                ?: '—',
            'payer_id' => $payerId,
            'payer_id_label' => $payerIdLabel,
            'programme' => $student?->program?->name,
            'category_label' => FeeSchedule::label($category),
            'installment_percent' => $installmentPercent,
            'invoice_number' => $invoice->number,
            'payment_method' => $this->paymentMethodLabel($payment->method ?: 'online'),
            'reference' => $payment->reference ?: '—',
            'paid_at' => optional($payment->created_at)->format('d M Y, h:i A') ?: '—',
            'amount' => $amount,
            'amount_words' => NairaWords::phrase($amount),
            'items' => $invoice->items->map(fn ($item) => [
                'description' => $item->description,
                'amount' => $item->amount,
            ])->all(),
            'generated_at' => now()->format('d M Y, h:i A'),
        ])->render();

        $filename = 'receipt-'.($payment->receipt_no ?: $invoice->number).'.html';
        $headers = ['Content-Type' => 'text/html; charset=UTF-8'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$filename.'"';
        }

        return response($html, 200, $headers);
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'matric' => 'required_without:student_id|string',
            'student_id' => 'required_without:matric|exists:students,id',
            'fee_item_ids' => 'required_without:fee_item_id|array|min:1',
            'fee_item_ids.*' => 'integer|exists:fee_items,id',
            'fee_item_id' => 'nullable|exists:fee_items,id',
            'category' => ['nullable', 'string', Rule::in(FeeSchedule::categories())],
            'installment_percent' => ['nullable', 'integer', Rule::in(FeeSchedule::INSTALLMENT_PERCENTS)],
            'amount' => 'nullable|numeric|min:0',
        ]);

        return $this->officeGate('finance.generate_invoice', null, $data, 'Generate invoice', function () use ($data) {
            return $this->generateNow($data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function generateNow(array $data)
    {
        $student = isset($data['student_id'])
            ? Student::query()->with(['user', 'program'])->findOrFail($data['student_id'])
            : $this->findStudentByMatric((string) $data['matric']);

        $feeIds = array_values(array_unique(array_map(
            'intval',
            $data['fee_item_ids'] ?? (isset($data['fee_item_id']) ? [$data['fee_item_id']] : []),
        )));

        if ($feeIds !== []) {
            $fees = FeeItem::query()
                ->whereIn('id', $feeIds)
                ->where('is_active', true)
                ->where('wallet_allowed', true)
                ->get()
                ->keyBy('id');
            if ($fees->count() !== count($feeIds)) {
                return response()->json(['message' => 'Select active wallet fee catalog items only.'], 422);
            }
            if ($fees->contains(fn (FeeItem $fee) => FeeSchedule::isScheduleCategory((string) $fee->category))) {
                return response()->json([
                    'message' => 'Programme-schedule fees cannot be invoiced here. Choose operational fee items, or use the student portal for school fees.',
                ], 422);
            }
            $ordered = [];
            foreach ($feeIds as $id) {
                $ordered[] = $fees->get($id);
            }
            try {
                $invoice = $this->invoices->createFromFeeItems(
                    $student,
                    $ordered,
                    (int) ($data['installment_percent'] ?? 100),
                );
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $this->audit->record('invoice.created', 'Invoice '.$invoice->number, 'fees', 'invoice', $invoice->id, null, $invoice);

            return $invoice;
        }

        if (($data['category'] ?? null) === 'tuition') {
            $percent = (int) ($data['installment_percent'] ?? 100);
            try {
                $invoice = $this->invoices->createTuitionInvoice($student, $percent);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $this->audit->record('invoice.created', 'Tuition invoice '.$invoice->number, 'fees', 'invoice', $invoice->id, null, $invoice);

            return $invoice;
        }

        return response()->json(['message' => 'Select at least one fee item.'], 422);
    }

    public function studentStatus(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $request->validate([
            'matric' => 'required_without:student_id|string',
            'student_id' => 'required_without:matric|exists:students,id',
        ]);

        $student = isset($data['student_id'])
            ? Student::query()->with(['user', 'program', 'wallet'])->findOrFail($data['student_id'])
            : $this->findStudentByMatric((string) $data['matric']);

        $student->loadMissing(['user', 'program', 'wallet']);

        $invoices = Invoice::query()
            ->with([
                'items',
                'payments' => fn ($query) => $query->whereNotIn('status', ['failed', 'cancelled'])->latest(),
                'rebates.rebateType',
            ])
            ->where(function ($query) use ($student) {
                $query->where('student_id', $student->id);
                if ($student->user_id) {
                    $query->orWhere('user_id', $student->user_id);
                }
            })
            ->latest()
            ->get();

        $settlements = [];
        foreach ($invoices as $invoice) {
            $settlements[$invoice->id] = InvoiceSettlement::sync($invoice, $invoice->payments);
        }

        $finance = StudentFinanceStatus::summarize($student, $invoices);
        $walletBalance = round((float) ($student->wallet?->balance ?? 0), 2);

        $invoiceIds = $invoices->pluck('id')->filter()->values();
        $payments = Payment::query()
            ->whereNotIn('status', ['failed', 'cancelled'])
            ->where(function ($query) use ($student, $invoiceIds) {
                $query->whereRaw('1 = 0');
                if ($student->user_id) {
                    $query->orWhere('user_id', $student->user_id);
                }
                if ($invoiceIds->isNotEmpty()) {
                    $query->orWhereIn('invoice_id', $invoiceIds);
                }
            })
            ->latest()
            ->limit(100)
            ->get();

        $payments = $payments
            ->concat($invoices->flatMap(fn (Invoice $invoice) => $invoice->payments))
            ->unique('id')
            ->values();

        $walletTransactions = $student->wallet
            ? WalletTransaction::query()
                ->where('wallet_id', $student->wallet->id)
                ->latest('id')
                ->limit(50)
                ->get()
            : collect();

        return [
            'student' => [
                'id' => $student->id,
                'name' => trim("{$student->first_name} {$student->last_name}") ?: ($student->user?->name ?: '—'),
                'matric_number' => $student->matric_number,
                'student_number' => $student->student_number,
                'email' => $student->user?->email,
                'program' => $student->program?->name,
                'current_level' => $student->current_level,
                'status' => $student->status,
            ],
            'summary' => [
                'wallet_balance' => $walletBalance,
                'billed' => $finance['billed'],
                'rebate_total' => $finance['rebate_total'],
                'paid' => $finance['paid'],
                'outstanding' => $finance['outstanding'],
                'school_fees' => $finance['school_fees'],
                'tuition_outstanding' => $finance['tuition_outstanding'],
                'clearance' => $finance['clearance'],
                'invoice_count' => $finance['invoice_count'],
                'open_count' => $finance['open_count'],
            ],
            'invoices' => $invoices->map(function (Invoice $invoice) use ($settlements) {
                $settlement = $settlements[$invoice->id];

                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'category' => $invoice->category,
                    'amount' => $settlement['billed'],
                    'installment_amount' => $settlement['installment'],
                    'full_amount' => (float) ($invoice->full_amount ?: $invoice->amount),
                    'installment_percent' => $invoice->installment_percent !== null ? (int) $invoice->installment_percent : null,
                    'amount_paid' => $settlement['paid'],
                    'balance' => $settlement['balance'],
                    'rebate_total' => $settlement['rebate'],
                    'status' => $settlement['status'],
                    'created_at' => $invoice->created_at,
                    'items' => $invoice->items->map(fn ($item) => [
                        'id' => $item->id,
                        'description' => $item->description,
                        'amount' => (float) $item->amount,
                    ])->values(),
                    'payments' => $invoice->payments->map(fn (Payment $payment) => [
                        'id' => $payment->id,
                        'invoice_id' => $payment->invoice_id,
                        'amount' => (float) $payment->amount,
                        'method' => $payment->method,
                        'purpose' => $payment->purpose,
                        'reference' => $payment->reference,
                        'receipt_no' => $payment->receipt_no,
                        'status' => $payment->status,
                        'created_at' => $payment->created_at,
                    ])->values(),
                    'rebates' => $invoice->rebates->map(fn ($rebate) => [
                        'id' => $rebate->id,
                        'type_name' => $rebate->rebateType?->name,
                        'kind' => $rebate->kind,
                        'value' => (float) $rebate->value,
                        'amount' => (float) $rebate->amount,
                        'reason' => $rebate->reason,
                        'created_at' => $rebate->created_at,
                    ])->values(),
                ];
            })->values(),
            'payments' => $this->mapStudentPayments($payments, $invoices, $settlements),
            'wallet_transactions' => $walletTransactions->map(fn ($row) => [
                'id' => $row->id,
                'type' => $row->type,
                'amount' => (float) $row->amount,
                'reference' => $row->reference,
                'description' => $row->description,
                'created_at' => $row->created_at,
            ])->values(),
            'wallet' => [
                'id' => $student->wallet?->id,
                'balance' => $walletBalance,
                'transactions' => $walletTransactions->map(fn ($row) => [
                    'id' => $row->id,
                    'type' => $row->type,
                    'amount' => (float) $row->amount,
                    'reference' => $row->reference,
                    'description' => $row->description,
                    'created_at' => $row->created_at,
                ])->values(),
            ],
        ];
    }

    /**
     * Invoice settlements only. Wallet top-ups are omitted so the list totals match summary paid.
     *
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, Invoice>  $invoices
     * @param  array<int, array{billed: float, rebate: float, paid: float, balance: float, status: string}>  $settlements
     * @return Collection<int, array<string, mixed>>
     */
    private function mapStudentPayments($payments, $invoices, array $settlements = [])
    {
        $rows = collect();
        $byInvoice = $payments
            ->filter(fn (Payment $payment) => $payment->invoice_id && InvoiceSettlement::countsTowardInvoice($payment))
            ->groupBy('invoice_id');

        foreach ($invoices as $invoice) {
            $settlement = $settlements[$invoice->id] ?? InvoiceSettlement::for($invoice, $byInvoice[$invoice->id] ?? collect());
            if ($settlement['paid'] <= 0) {
                continue;
            }

            $remaining = $settlement['paid'];
            $matches = ($byInvoice[$invoice->id] ?? collect())
                ->sortBy(fn (Payment $payment) => $payment->created_at?->timestamp ?? 0)
                ->values();

            foreach ($matches as $payment) {
                if ($remaining <= 0.009) {
                    break;
                }
                $applied = round(min((float) $payment->amount, $remaining), 2);
                if ($applied <= 0) {
                    continue;
                }
                $rows->push([
                    'id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $applied,
                    'method' => $payment->method,
                    'purpose' => $payment->purpose ?: $invoice->category,
                    'reference' => $payment->reference,
                    'receipt_no' => $payment->receipt_no,
                    'status' => 'successful',
                    'created_at' => $payment->created_at,
                ]);
                $remaining = round($remaining - $applied, 2);
            }
        }

        return $rows->sortByDesc(fn (array $row) => (string) ($row['created_at'] ?? ''))->values();
    }

    private function findStudentByMatric(string $matric): Student
    {
        $key = strtoupper(preg_replace('/\s+/', '', trim($matric)) ?: '');
        abort_if($key === '', 422, 'Enter a matric number.');

        $student = Student::query()
            ->with(['user', 'program'])
            ->where(function ($builder) use ($key) {
                $builder->whereRaw('UPPER(REPLACE(COALESCE(matric_number, ""), " ", "")) = ?', [$key])
                    ->orWhereRaw('UPPER(REPLACE(COALESCE(student_number, ""), " ", "")) = ?', [$key]);
            })
            ->first();
        abort_unless($student, 422, 'No student was found with that matric number.');

        return $student;
    }

    public function dashboard(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        [$from, $to] = $this->dashboardPeriod($request);

        return $this->universityStatement->summarize($from, $to);
    }

    public function exportDashboard(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $request->validate([
            'format' => 'required|in:pdf,excel,word',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        [$from, $to] = $this->dashboardPeriod($request);
        $statement = $this->universityStatement->summarize($from, $to);
        $statement['filter_summary'] = $this->universityStatement->filterSummary($from, $to);

        return $this->universityStatement->export($data['format'], $statement);
    }

    /**
     * @return array{0: \Carbon\CarbonInterface|null, 1: \Carbon\CarbonInterface|null}
     */
    private function dashboardPeriod(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        return [
            isset($data['from']) ? \Carbon\Carbon::parse($data['from'])->startOfDay() : null,
            isset($data['to']) ? \Carbon\Carbon::parse($data['to'])->endOfDay() : null,
        ];
    }

    public function studentRoster(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $session = $this->resolveRosterSession($request);
        $query = $this->studentRosterQuery($request, $session['id']);
        $page = $query->paginate($perPage);
        $this->hydrateFinanceSummaries(collect($page->items()));
        $page->through(fn (Student $student) => $this->mapStudentFinanceRow($student));

        return [
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'session' => $session,
            'lookups' => $this->studentRosterLookups($session),
        ];
    }

    public function exportStudentRoster(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $request->validate([
            'format' => 'required|in:pdf,excel,word',
        ]);

        $session = $this->resolveRosterSession($request);
        $students = $this->studentRosterQuery($request, $session['id'])
            ->limit(StudentFinanceExportService::MAX_ROWS)
            ->get();
        $this->hydrateFinanceSummaries($students);

        $rows = $students->map(function (Student $student) {
            $row = $this->mapStudentFinanceRow($student);

            return [
                'name' => $row['name'],
                'matric' => $row['matric_number'] ?: ($row['student_number'] ?: '—'),
                'programme' => $row['program'] ?: '—',
                'college' => $row['college'] ?: '—',
                'level' => $row['current_level'] ? $row['current_level'].'L' : '—',
                'wallet' => number_format((float) $row['wallet_balance'], 2),
                'billed' => number_format((float) $row['billed'], 2),
                'paid' => number_format((float) $row['paid'], 2),
                'outstanding' => number_format((float) $row['outstanding'], 2),
                'clearance' => $row['clearance'] === 'cleared' ? 'Cleared' : 'Outstanding',
            ];
        })->values();

        $title = 'Students Financial Status';
        if ($session['label']) {
            $title .= ' — '.$session['label'];
        }

        return $this->studentFinanceExports->export(
            $data['format'],
            $rows,
            $title,
            $this->studentRosterFilterSummary($request, $session),
        );
    }

    /**
     * @return array{id: int|null, label: string|null, is_current: bool, scope: string}
     */
    private function resolveRosterSession(Request $request): array
    {
        $current = AcademicTerm::query()->with('session')->where('is_current', true)->first();
        $currentSession = $current?->session;
        $raw = $request->input('academic_session_id');

        if ($raw === 'all' || $raw === '0') {
            return ['id' => null, 'label' => null, 'is_current' => false, 'scope' => 'all'];
        }

        $sessionId = $request->filled('academic_session_id')
            ? (int) $raw
            : ($currentSession?->id);

        if (! $sessionId) {
            return [
                'id' => null,
                'label' => $currentSession?->label,
                'is_current' => true,
                'scope' => 'current',
            ];
        }

        $session = AcademicSession::query()->find($sessionId);

        return [
            'id' => $session?->id,
            'label' => $session?->label,
            'is_current' => (int) $session?->id === (int) $currentSession?->id,
            'scope' => 'session',
        ];
    }

    private function studentRosterQuery(Request $request, ?int $sessionId): Builder
    {
        $wallet = Wallet::query()
            ->select('balance')
            ->whereColumn('wallets.student_id', 'students.id')
            ->limit(1);

        $query = Student::query()
            ->select('students.*')
            ->selectSub($wallet, 'wallet_balance')
            ->with(['user:id,name,email', 'program.department.faculty'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($sessionId) {
            $isCurrent = AcademicTerm::query()
                ->where('is_current', true)
                ->whereHas('session', fn ($session) => $session->whereKey($sessionId))
                ->exists();
            if (! $isCurrent) {
                $query->whereHas(
                    'application.intake.term',
                    fn ($term) => $term->where('academic_session_id', $sessionId),
                );
            }
        }

        if ($request->filled('program_id')) {
            $query->where('program_id', (int) $request->input('program_id'));
        }
        if ($request->filled('department_id')) {
            $query->whereHas('program', fn ($program) => $program->where('department_id', (int) $request->input('department_id')));
        }
        if ($request->filled('faculty_id') || $request->filled('college_id')) {
            $facultyId = (int) $request->input('faculty_id', $request->input('college_id'));
            $query->whereHas('program.department', fn ($department) => $department->where('faculty_id', $facultyId));
        }
        if ($request->filled('level')) {
            $query->where('current_level', $request->input('level'));
        }
        if ($request->filled('student_id')) {
            $query->where('id', (int) $request->input('student_id'));
        }
        if ($request->filled('search')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->input('search'))).'%';
            $query->where(function (Builder $builder) use ($term) {
                $builder->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('middle_name', 'like', $term)
                    ->orWhere('matric_number', 'like', $term)
                    ->orWhere('student_number', 'like', $term)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term)->orWhere('email', 'like', $term))
                    ->orWhereHas('program', fn ($program) => $program->where('name', 'like', $term)->orWhere('code', 'like', $term));
            });
        }

        $clearance = (string) $request->input('clearance', '');
        if ($clearance === 'outstanding' || $clearance === 'cleared') {
            [$schoolFeesSql, $schoolBindings] = $this->rosterSchoolFeesSql();
            [$paidSql, $paidBindings] = $this->rosterTuitionPaidSql();
            if ($clearance === 'cleared') {
                $query->whereRaw(
                    $schoolFeesSql.' > 0.009 AND '.$paidSql.' >= '.$schoolFeesSql.' - 0.009',
                    array_merge($schoolBindings, $paidBindings, $schoolBindings)
                );
            } else {
                $query->whereRaw(
                    $schoolFeesSql.' <= 0.009 OR '.$paidSql.' < '.$schoolFeesSql.' - 0.009',
                    array_merge($schoolBindings, $paidBindings, $schoolBindings)
                );
            }
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function rosterSchoolFeesSql(): array
    {
        $invoiceFull = Invoice::query()
            ->selectRaw('COALESCE(MAX(COALESCE(full_amount, amount)), 0)')
            ->where('category', 'tuition')
            ->whereNotIn('status', ['cancelled', 'disabled'])
            ->where(function (Builder $query) {
                $query->whereColumn('invoices.student_id', 'students.id')
                    ->orWhereColumn('invoices.user_id', 'students.user_id');
            });
        $schedule = ProgrammeFee::query()
            ->join('fee_items', 'fee_items.id', '=', 'programme_fees.fee_item_id')
            ->whereColumn('programme_fees.program_id', 'students.program_id')
            ->where('programme_fees.is_active', true)
            ->where('fee_items.is_active', true)
            ->where(function (Builder $query) {
                $query->where('programme_fees.level_code', 'all')
                    ->orWhereColumn('programme_fees.level_code', 'students.current_level')
                    ->orWhereRaw("programme_fees.level_code = REPLACE(CAST(students.current_level AS CHAR), 'L', '')");
            })
            ->where(function (Builder $query) {
                $query->where(function (Builder $inner) {
                    $inner->whereNull('programme_fees.installment_tranche')
                        ->orWhereIn('programme_fees.installment_tranche', [1, 2, 3, 4]);
                })->where(function (Builder $inner) {
                    $inner->whereNull('fee_items.installment_tranche')
                        ->orWhereIn('fee_items.installment_tranche', [1, 2, 3, 4]);
                });
            })
            ->selectRaw('COALESCE(SUM(COALESCE(programme_fees.amount, fee_items.amount, 0)), 0)');

        return [
            'CASE WHEN COALESCE(('.$invoiceFull->toSql().'), 0) > COALESCE(('.$schedule->toSql().'), 0)'
            .' THEN COALESCE(('.$invoiceFull->toSql().'), 0) ELSE COALESCE(('.$schedule->toSql().'), 0) END',
            array_merge(
                $invoiceFull->getBindings(),
                $schedule->getBindings(),
                $invoiceFull->getBindings(),
                $schedule->getBindings(),
            ),
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function rosterTuitionPaidSql(): array
    {
        $paid = Invoice::query()
            ->join('payments', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.category', 'tuition')
            ->whereNotIn('invoices.status', ['cancelled', 'disabled'])
            ->whereIn('payments.status', ['successful', 'paid'])
            ->where(function (Builder $query) {
                $query->whereNull('payments.purpose')
                    ->orWhereNotIn('payments.purpose', ['wallet_topup', 'wallet_funding']);
            })
            ->where(function (Builder $query) {
                $query->whereColumn('invoices.student_id', 'students.id')
                    ->orWhereColumn('invoices.user_id', 'students.user_id');
            })
            ->selectRaw('COALESCE(SUM(payments.amount), 0)');

        return ['COALESCE(('.$paid->toSql().'), 0)', $paid->getBindings()];
    }

    /**
     * @param  Collection<int, Student>  $students
     */
    private function hydrateFinanceSummaries(Collection $students): void
    {
        if ($students->isEmpty()) {
            return;
        }

        $studentIds = $students->pluck('id')->all();
        $userIds = $students->pluck('user_id')->filter()->unique()->values()->all();
        foreach ($students as $student) {
            $student->loadMissing('program');
        }

        $invoices = Invoice::query()
            ->with(['payments' => fn ($query) => $query->whereNotIn('status', ['failed', 'cancelled'])])
            ->where(function (Builder $query) use ($studentIds, $userIds) {
                $query->whereIn('student_id', $studentIds);
                if ($userIds !== []) {
                    $query->orWhereIn('user_id', $userIds);
                }
            })
            ->get();

        $byStudent = $invoices->groupBy('student_id');
        $byUser = $invoices->groupBy('user_id');

        foreach ($students as $student) {
            $rows = collect($byStudent->get($student->id, []))
                ->concat($student->user_id ? $byUser->get($student->user_id, []) : [])
                ->unique('id')
                ->values();
            $student->setAttribute('finance_summary', StudentFinanceStatus::summarize($student, $rows));
        }
    }

    private function mapStudentFinanceRow(Student $student): array
    {
        $summary = $student->getAttribute('finance_summary')
            ?? StudentFinanceStatus::summarize($student);

        return [
            'id' => $student->id,
            'name' => trim("{$student->first_name} {$student->last_name}") ?: ($student->user?->name ?: '—'),
            'matric_number' => $student->matric_number,
            'student_number' => $student->student_number,
            'email' => $student->user?->email,
            'program' => $student->program?->name,
            'program_id' => $student->program_id,
            'department' => $student->program?->department?->name,
            'college' => $student->program?->department?->faculty?->name,
            'current_level' => $student->current_level,
            'status' => $student->status,
            'wallet_balance' => round((float) ($student->wallet_balance ?? $student->wallet?->balance ?? 0), 2),
            'billed' => $summary['billed'],
            'paid' => $summary['paid'],
            'outstanding' => $summary['outstanding'],
            'clearance' => $summary['clearance'],
        ];
    }

    /**
     * @param  array{id: int|null, label: string|null, is_current: bool, scope: string}  $session
     */
    private function studentRosterLookups(array $session): array
    {
        $currentId = AcademicTerm::query()->where('is_current', true)->value('academic_session_id');

        return [
            'current_session_id' => $currentId ? (int) $currentId : $session['id'],
            'sessions' => AcademicSession::query()
                ->with('semesters')
                ->orderByDesc('id')
                ->get(['id', 'label'])
                ->map(fn (AcademicSession $row) => [
                    'id' => $row->id,
                    'label' => $row->label,
                    'is_current' => (int) $row->id === (int) $currentId,
                ])
                ->values(),
            'levels' => AcademicLevel::query()
                ->orderBy('study_level')
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name'])
                ->map(fn (AcademicLevel $level) => [
                    'value' => $level->code,
                    'label' => $level->name ?: ($level->code ? $level->code.'L' : 'Level'),
                ])
                ->values(),
        ];
    }

    /**
     * @param  array{id: int|null, label: string|null, is_current: bool, scope: string}  $session
     * @return list<string>
     */
    private function studentRosterFilterSummary(Request $request, array $session): array
    {
        $parts = [];
        if ($session['scope'] === 'all') {
            $parts[] = 'Session: All';
        } elseif ($session['label']) {
            $parts[] = 'Session: '.$session['label'];
        }
        if ($request->filled('search')) {
            $parts[] = 'Search: '.$request->input('search');
        }
        $facultyId = (int) $request->input('faculty_id', $request->input('college_id', 0));
        if ($facultyId) {
            $college = Faculty::query()->whereKey($facultyId)->value('name');
            $parts[] = 'College: '.($college ?: '#'.$facultyId);
        }
        if ($request->filled('department_id')) {
            $department = Department::query()->whereKey((int) $request->input('department_id'))->value('name');
            $parts[] = 'Department: '.($department ?: '#'.$request->input('department_id'));
        }
        if ($request->filled('program_id')) {
            $name = Program::query()->whereKey((int) $request->input('program_id'))->value('name');
            $parts[] = 'Programme: '.($name ?: '#'.$request->input('program_id'));
        }
        if ($request->filled('level')) {
            $parts[] = 'Level: '.$request->input('level');
        }
        if ($request->filled('clearance')) {
            $parts[] = 'Clearance: '.((string) $request->input('clearance') === 'cleared' ? 'Cleared' : 'Outstanding');
        }

        return $parts;
    }

    public function myProgrammeFeeSchedule(Request $request)
    {
        $student = $request->user()->student()->with('program')->first();
        if (! $student?->program_id) {
            return [
                'schedule_set' => false,
                'total_amount' => null,
                'line_count' => 0,
                'program' => null,
                'tuition_percent_paid' => 0,
                'available_installment_percents' => FeeSchedule::INSTALLMENT_PERCENTS,
            ];
        }

        $this->arrears->ensureForStudent($student);
        $prior = $this->arrears->priorUnpaid($student);

        $lines = ProgrammeFeeResolver::forStudent($student);
        $total = round((float) $lines->sum(fn ($line) => $line->effective_amount), 2);

        return [
            'schedule_set' => $lines->isNotEmpty() && $total > 0,
            'total_amount' => $total > 0 ? $total : null,
            'line_count' => $lines->count(),
            'program' => $student->program?->only(['id', 'name', 'code']),
            'tuition_percent_paid' => TuitionProgress::currentSessionPercent($student),
            'available_installment_percents' => $prior->isNotEmpty()
                ? []
                : TuitionProgress::availableInstallmentPercents($student),
            'prior_unpaid_count' => $prior->count(),
            'prior_unpaid_amount' => round((float) $prior->sum('balance'), 2),
        ];
    }

    public function createTuitionInstallment(Request $request)
    {
        $user = $request->user();
        $student = $user->student;
        abort_unless($student, 422, 'Only matriculated students can generate tuition invoices.');
        $this->arrears->ensureForStudent($student);

        $data = $request->validate([
            'installment_percent' => ['required', 'integer', Rule::in(FeeSchedule::INSTALLMENT_PERCENTS)],
        ]);

        try {
            $this->arrears->assertPriorSettled($student);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hasOpen = Invoice::query()
            ->where('student_id', $student->id)
            ->where('category', 'tuition')
            ->whereIn('status', ['unpaid', 'partial'])
            ->exists();
        if ($hasOpen) {
            return response()->json(['message' => 'Pay or clear your open tuition invoice before creating another installment.'], 422);
        }

        $percent = (int) $data['installment_percent'];
        $available = TuitionProgress::availableInstallmentPercents($student);
        if (! in_array($percent, $available, true)) {
            return response()->json([
                'message' => $available === []
                    ? 'Tuition is already paid in full.'
                    : 'This installment has already been paid. Choose the next unpaid share.',
            ], 422);
        }

        try {
            $invoice = $this->invoices->createTuitionInvoice($student->load(['user', 'program']), $percent);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $this->audit->record('invoice.created', 'Tuition installment '.$invoice->number, 'fees', 'invoice', $invoice->id, null, $invoice, null, $user);

        return $invoice;
    }

    private function paymentMethodLabel(?string $method): string
    {
        $method = strtolower((string) ($method ?: 'online'));

        return match (true) {
            in_array($method, ['paystack', 'online', 'card', 'gateway'], true) => 'Online',
            $method === 'wallet' => 'Wallet',
            $method === 'cash' => 'Cash',
            in_array($method, ['bank', 'transfer', 'bank_transfer'], true) => 'Bank transfer',
            $method === 'pos' => 'POS',
            in_array($method, ['legacy_import', 'import'], true) => 'Recorded payment',
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }

    /**
     * @return array{name: string, motto: string, office: string, address: string, contact: string}
     */
    private function receiptInstitution(): array
    {
        $campus = Campus::query()->where('is_active', true)->orderBy('id')->first()
            ?? Campus::query()->orderBy('id')->first();

        return [
            'name' => (string) Setting::getValue('university_name', 'Bells University of Technology'),
            'motto' => (string) Setting::getValue('university_motto', 'Chords of Knowledge'),
            'office' => (string) Setting::getValue('bursary_office_title', 'Bursary Department'),
            'address' => trim(collect([
                $campus?->address,
                $campus?->city,
            ])->filter()->implode(', '))
                ?: 'KM 8, Idiroko Road, Benja Village, P.M.B 1015, Ota, Ogun State',
            'contact' => (string) Setting::getValue('university_contact', 'Telephone: 07087138753'),
        ];
    }

    private function invoiceListQuery(Request $request): Builder
    {
        $query = Invoice::query()
            ->with([
                'user:id,name,email,jamb_registration',
                'user.latestApplication',
                'student:id,user_id,first_name,last_name,matric_number,student_number,program_id',
                'application:id,user_id,application_number,jamb_registration',
                'academicSession:id,label',
                'rebates.rebateType',
            ])
            ->latest();
        if (! $request->user()->hasPermission('finance.invoices.manage')) {
            $query->where('user_id', $request->user()->id);
        }
        if ($request->filled('status') && (string) $request->input('status') !== 'all') {
            $query->where('status', (string) $request->input('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', (string) $request->input('category'));
        }
        $facultyId = (int) $request->input('faculty_id', $request->input('college_id', 0));
        if ($facultyId) {
            $query->whereHas('student.program.department', function ($departments) use ($facultyId) {
                $departments->where('faculty_id', $facultyId);
            });
        }
        if ($request->filled('department_id')) {
            $query->whereHas('student.program', function ($programs) use ($request) {
                $programs->where('department_id', (int) $request->input('department_id'));
            });
        }
        if ($request->filled('program_id')) {
            $query->whereHas('student', function ($students) use ($request) {
                $students->where('program_id', (int) $request->input('program_id'));
            });
        }
        if ($request->filled('search')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->input('search'))).'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('number', 'like', $term)
                    ->orWhereHas('user', function ($users) use ($term) {
                        $users->where('name', 'like', $term)->orWhere('email', 'like', $term);
                    })
                    ->orWhereHas('student', function ($students) use ($term) {
                        $students->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('matric_number', 'like', $term)
                            ->orWhere('student_number', 'like', $term);
                    });
            });
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }
        if ($level = ListSessionLevelFilter::levelCode($request)) {
            $query->whereHas('student', fn ($students) => $students->where('current_level', $level));
        }
        if ($sessionId = ListSessionLevelFilter::sessionId($request)) {
            $query->where(function ($builder) use ($sessionId) {
                $builder->whereHas('application.intake.term', fn ($term) => $term->where('academic_session_id', $sessionId))
                    ->orWhereHas('student.application.intake.term', fn ($term) => $term->where('academic_session_id', $sessionId));
            });
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function invoiceFilterSummary(Request $request): array
    {
        $summary = [];
        $status = (string) $request->input('status', '');
        if ($status !== '' && $status !== 'all') {
            $summary[] = 'Status: '.($status === 'cancelled' ? 'Disabled' : ucfirst($status));
        }
        if ($request->filled('category')) {
            $summary[] = 'Category: '.FeeSchedule::label((string) $request->input('category'));
        }
        $facultyId = (int) $request->input('faculty_id', $request->input('college_id', 0));
        if ($facultyId) {
            $college = Faculty::query()->whereKey($facultyId)->value('name');
            $summary[] = 'College: '.($college ?: '#'.$facultyId);
        }
        if ($request->filled('department_id')) {
            $department = Department::query()->whereKey((int) $request->input('department_id'))->value('name');
            $summary[] = 'Department: '.($department ?: '#'.$request->input('department_id'));
        }
        if ($request->filled('program_id')) {
            $program = Program::query()->whereKey((int) $request->input('program_id'))->value('name');
            $summary[] = 'Programme: '.($program ?: '#'.$request->input('program_id'));
        }
        if ($request->filled('from') || $request->filled('to')) {
            $summary[] = 'Date: '.($request->input('from') ?: '…').' to '.($request->input('to') ?: '…');
        }
        if ($request->filled('search')) {
            $summary[] = 'Search: '.$request->input('search');
        }
        if ($sessionId = ListSessionLevelFilter::sessionId($request)) {
            $label = AcademicSession::query()->whereKey($sessionId)->value('label');
            $summary[] = 'Session: '.($label ?: '#'.$sessionId);
        }
        if ($level = ListSessionLevelFilter::levelCode($request)) {
            $summary[] = 'Level: '.$level;
        }

        return $summary;
    }
}
