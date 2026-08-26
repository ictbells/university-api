<?php

namespace App\Services;

use App\Mail\TranscriptRequestPaidMail;
use App\Mail\TranscriptRequestReadyMail;
use App\Mail\TranscriptRequestRejectedMail;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\Program;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentLevelProgression;
use App\Models\TranscriptRequest;
use App\Models\User;
use App\Support\TranscriptBuilder;
use App\Support\TranscriptRequestSettings;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class TranscriptRequestService
{
    public function __construct(
        private InvoiceService $invoices,
        private PaystackService $paystack,
        private AuditWriter $audit,
    ) {}

    public function meta(): array
    {
        $settings = TranscriptRequestSettings::all();
        $fee = $this->resolveFeeItem();

        return [
            'enabled' => TranscriptRequestSettings::enabled() && $fee !== null,
            'university' => (string) Setting::getValue('university_name', 'Bells University of Technology'),
            'fee' => $fee ? [
                'name' => $fee->name,
                'amount' => (float) $fee->amount,
                'category' => $fee->category,
            ] : null,
            'delivery_modes' => TranscriptRequestSettings::enabledDeliveryModes(),
            'collect_instructions' => $settings['transcript_collect_instructions'],
            'unavailable_reason' => $this->unavailableReason($fee),
        ];
    }

    public function lookup(string $matricNumber, string $email): array
    {
        $fee = $this->resolveFeeItem();
        if (! TranscriptRequestSettings::enabled() || ! $fee) {
            throw new RuntimeException($this->unavailableReason($fee) ?: 'Official transcript requests are not available.');
        }

        $student = $this->findStudentForRequest($matricNumber, $email);
        $programmes = $this->programmesForStudent($student);
        if ($programmes === []) {
            throw new RuntimeException('No programme is linked to this student record. Contact the Registry.');
        }

        return [
            'student' => [
                'name' => trim(($student->first_name ?? '').' '.($student->last_name ?? '')),
                'matric_number' => $student->matric_number,
            ],
            'programmes' => $programmes,
            'fee' => [
                'name' => $fee->name,
                'amount' => (float) $fee->amount,
            ],
        ];
    }

    public function create(
        string $matricNumber,
        string $email,
        int $programId,
        int $copies = 1,
        ?string $purpose = null,
    ): array {
        $fee = $this->resolveFeeItem();
        if (! TranscriptRequestSettings::enabled() || ! $fee) {
            throw new RuntimeException($this->unavailableReason($fee) ?: 'Official transcript requests are not available.');
        }

        $student = $this->findStudentForRequest($matricNumber, $email);
        $user = $student->user;
        if (! $user) {
            throw new RuntimeException('Unable to verify student account. Contact the Registry.');
        }

        $allowedIds = collect($this->programmesForStudent($student))->pluck('id')->all();
        if (! in_array($programId, $allowedIds, true)) {
            throw new RuntimeException('Select a programme linked to this student.');
        }

        $program = Program::query()->findOrFail($programId);

        $existing = TranscriptRequest::query()
            ->where('student_id', $student->id)
            ->where('program_id', $programId)
            ->where('status', 'awaiting_payment')
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->update([
                'contact_email' => strtolower(trim($email)),
                'copies' => max(1, min(10, $copies)),
                'purpose' => $purpose ? Str::limit(trim($purpose), 255, '') : null,
            ]);
            $request = $existing->fresh(['invoice', 'program']);
            if (! $request->invoice || ! $request->invoice->isPayable()) {
                $invoice = $this->invoices->createForFee(
                    $user,
                    $fee,
                    null,
                    $student->id,
                    null,
                    $fee->name.' — '.$program->name.' ('.$copies.' '.Str::plural('copy', $copies).')',
                );
                $request->update(['invoice_id' => $invoice->id]);
                $request = $request->fresh(['invoice', 'program']);
            }
        } else {
            $invoice = $this->invoices->createForFee(
                $user,
                $fee,
                null,
                $student->id,
                null,
                $fee->name.' — '.$program->name.' ('.$copies.' '.Str::plural('copy', $copies).')',
            );

            $request = TranscriptRequest::query()->create([
                'public_token' => Str::lower(Str::random(48)),
                'student_id' => $student->id,
                'program_id' => $programId,
                'invoice_id' => $invoice->id,
                'contact_email' => strtolower(trim($email)),
                'copies' => max(1, min(10, $copies)),
                'purpose' => $purpose ? Str::limit(trim($purpose), 255, '') : null,
                'status' => 'awaiting_payment',
            ]);
            $request->load(['invoice', 'program']);
        }

        $payment = $this->initializePayment($request);

        return [
            'request' => $this->publicPayload($request),
            'payment' => $payment,
        ];
    }

    public function initializePayment(TranscriptRequest $request): array
    {
        $request->loadMissing(['invoice', 'student.user']);
        abort_unless($request->status === 'awaiting_payment', 422, 'This request is not awaiting payment.');
        $invoice = $request->invoice;
        abort_unless($invoice && $invoice->isPayable(), 422, 'No payable invoice for this request.');
        $user = $request->student?->user;
        abort_unless($user, 422, 'Student account missing.');

        $callback = rtrim((string) config('app.student_url'), '/')
            .'/transcript-request/callback?token='.urlencode($request->public_token);

        return $this->paystack->initializeInvoice($user, $invoice, $callback);
    }

    public function showPublic(string $token): array
    {
        $request = $this->findByToken($token);

        return $this->publicPayload($request);
    }

    public function verifyPayment(string $token, string $reference): array
    {
        $request = $this->findByToken($token);
        $payment = $this->paystack->verify($reference);
        abort_unless(
            (int) $payment->invoice_id === (int) $request->invoice_id,
            422,
            'Payment does not match this transcript request.',
        );

        $request->refresh();

        return [
            'request' => $this->publicPayload($request),
            'payment' => $payment->load('invoice'),
        ];
    }

    public function markPaid(Invoice $invoice): void
    {
        $request = TranscriptRequest::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', ['awaiting_payment', 'cancelled'])
            ->first();

        if (! $request) {
            return;
        }

        $request->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        try {
            Mail::to($request->contact_email)->send(new TranscriptRequestPaidMail($request->fresh(['student'])));
        } catch (\Throwable) {
            // Payment succeeded even if mail fails.
        }
    }

    public function startProcessing(TranscriptRequest $request, User $staff): TranscriptRequest
    {
        abort_unless(in_array($request->status, ['paid', 'processing'], true), 422, 'Only paid requests can be processed.');

        if ($request->status === 'paid') {
            $request->update([
                'status' => 'processing',
                'processed_by' => $staff->id,
            ]);
            $this->audit->record(
                'transcript.processing',
                'Transcript request moved to processing',
                'transcripts',
                'transcript_request',
                $request->id,
            );
        }

        return $request->fresh(['student.program', 'invoice', 'processor']);
    }

    public function markReady(
        TranscriptRequest $request,
        User $staff,
        string $deliveryMode,
        ?UploadedFile $upload = null,
    ): TranscriptRequest {
        abort_unless(in_array($request->status, ['paid', 'processing'], true), 422, 'Request cannot be marked ready.');
        if (! in_array($deliveryMode, TranscriptRequestSettings::enabledDeliveryModes(), true)) {
            throw new InvalidArgumentException('That delivery mode is not enabled.');
        }

        $path = $request->artifact_path;

        if ($deliveryMode === 'uploaded_pdf') {
            if (! $upload) {
                throw new InvalidArgumentException('Upload a signed PDF to fulfill this request.');
            }
            $path = $upload->storeAs(
                'transcripts/'.$request->id,
                'official-'.Str::random(8).'.pdf',
                'local',
            );
        } elseif ($deliveryMode === 'generated_pdf') {
            $path = $this->storeGeneratedPdf($request, $staff);
        } else {
            $path = null;
        }

        $request->update([
            'status' => 'ready',
            'delivery_mode' => $deliveryMode,
            'artifact_path' => $path,
            'processed_by' => $staff->id,
            'ready_at' => now(),
            'rejected_reason' => null,
        ]);

        $fresh = $request->fresh(['student.program', 'invoice', 'processor']);
        Mail::to($fresh->contact_email)->send(new TranscriptRequestReadyMail($fresh));

        $this->audit->record(
            'transcript.ready',
            'Transcript request marked ready ('.$deliveryMode.')',
            'transcripts',
            'transcript_request',
            $request->id,
        );

        return $fresh;
    }

    public function reject(TranscriptRequest $request, User $staff, string $reason): TranscriptRequest
    {
        abort_unless(in_array($request->status, ['paid', 'processing', 'awaiting_payment'], true), 422, 'Request cannot be rejected.');

        $request->update([
            'status' => 'rejected',
            'rejected_reason' => Str::limit(trim($reason), 1000, ''),
            'processed_by' => $staff->id,
        ]);

        $fresh = $request->fresh(['student']);
        Mail::to($fresh->contact_email)->send(new TranscriptRequestRejectedMail($fresh));

        $this->audit->record(
            'transcript.rejected',
            'Transcript request rejected',
            'transcripts',
            'transcript_request',
            $request->id,
            null,
            ['reason' => $reason],
        );

        return $fresh;
    }

    public function downloadResponse(TranscriptRequest $request)
    {
        abort_unless($request->isDownloadable(), 404, 'Download is not available for this request.');
        abort_unless(Storage::disk('local')->exists($request->artifact_path), 404, 'File not found.');

        $matric = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($request->student?->matric_number ?: 'student')) ?: 'student';

        return Storage::disk('local')->download(
            $request->artifact_path,
            'official-transcript-'.$matric.'.pdf',
        );
    }

    public function staffPayload(TranscriptRequest $request): array
    {
        $request->loadMissing(['student.program', 'program.department', 'invoice', 'processor']);
        $student = $request->student;
        $programId = $request->program_id ? (int) $request->program_id : null;

        return [
            'id' => $request->id,
            'public_token' => $request->public_token,
            'status' => $request->status,
            'delivery_mode' => $request->delivery_mode,
            'copies' => $request->copies,
            'purpose' => $request->purpose,
            'contact_email' => $request->contact_email,
            'rejected_reason' => $request->rejected_reason,
            'paid_at' => $request->paid_at?->toIso8601String(),
            'ready_at' => $request->ready_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'has_artifact' => filled($request->artifact_path),
            'downloadable' => $request->isDownloadable(),
            'invoice' => $request->invoice?->only(['id', 'number', 'amount', 'balance', 'status', 'category']),
            'processor' => $request->processor?->only(['id', 'name', 'email']),
            'program' => $request->program ? [
                'id' => $request->program->id,
                'name' => $request->program->name,
                'code' => $request->program->code,
                'department' => $request->program->department?->name,
            ] : null,
            'student' => $student ? [
                'id' => $student->id,
                'name' => trim(($student->first_name ?? '').' '.($student->last_name ?? '')),
                'matric_number' => $student->matric_number,
                'programme' => $request->program?->name ?: $student->program?->name,
                'status' => $student->status,
            ] : null,
            'transcript' => $student ? TranscriptBuilder::forStudent($student, true, false, $programId) : null,
            'enabled_delivery_modes' => TranscriptRequestSettings::enabledDeliveryModes(),
        ];
    }

    public function publicPayload(TranscriptRequest $request): array
    {
        $request->loadMissing(['invoice', 'program']);

        return [
            'token' => $request->public_token,
            'status' => $request->status,
            'copies' => $request->copies,
            'program' => $request->program ? [
                'id' => $request->program->id,
                'name' => $request->program->name,
                'code' => $request->program->code,
            ] : null,
            'delivery_mode' => $request->delivery_mode,
            'downloadable' => $request->isDownloadable(),
            'paid_at' => $request->paid_at?->toIso8601String(),
            'ready_at' => $request->ready_at?->toIso8601String(),
            'amount' => $request->invoice ? (float) $request->invoice->amount : null,
            'invoice_number' => $request->invoice?->number,
            'invoice_status' => $request->invoice?->status,
        ];
    }

    public function findByToken(string $token): TranscriptRequest
    {
        return TranscriptRequest::query()
            ->where('public_token', $token)
            ->firstOrFail();
    }

    private function findStudentForRequest(string $matricNumber, string $email): Student
    {
        $matric = trim($matricNumber);
        $emailNorm = strtolower(trim($email));

        $student = Student::query()
            ->with('user')
            ->whereRaw('LOWER(matric_number) = ?', [strtolower($matric)])
            ->first();

        $match = $student
            && $student->user
            && strtolower((string) $student->user->email) === $emailNorm;

        if (! $match) {
            throw new RuntimeException('We could not match that matric number and email. Check your details or contact the Registry.');
        }

        return $student;
    }

    /**
     * Programmes linked to this student: current record, level-progression history,
     * applications on the same account, and curricula of courses they enrolled in.
     *
     * @return list<array{id: int, name: string, code: ?string, study_level: ?string, department: ?string, is_current: bool}>
     */
    private function programmesForStudent(Student $student): array
    {
        $ids = [];
        $currentId = $student->program_id ? (int) $student->program_id : null;
        if ($currentId) {
            $ids[$currentId] = true;
        }

        foreach (
            StudentLevelProgression::query()
                ->where('student_id', $student->id)
                ->whereNotNull('program_id')
                ->pluck('program_id') as $id
        ) {
            $ids[(int) $id] = true;
        }

        if ($student->user_id) {
            foreach (
                \App\Models\Application::query()
                    ->where('user_id', $student->user_id)
                    ->whereNotNull('program_id')
                    ->pluck('program_id') as $id
            ) {
                $ids[(int) $id] = true;
            }
        }

        $enrolledCourseIds = $student->enrollments()
            ->whereHas('offering')
            ->with('offering:id,course_id')
            ->get()
            ->pluck('offering.course_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($enrolledCourseIds !== []) {
            $fromCurriculum = Program::query()
                ->whereHas('courses', fn ($q) => $q->whereIn('courses.id', $enrolledCourseIds))
                ->pluck('id');
            foreach ($fromCurriculum as $id) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return Program::query()
            ->with('department:id,name')
            ->whereIn('id', array_keys($ids))
            ->orderBy('name')
            ->get()
            ->map(fn (Program $program) => [
                'id' => $program->id,
                'name' => $program->name,
                'code' => $program->code,
                'study_level' => $program->study_level,
                'department' => $program->department?->name,
                'is_current' => $currentId !== null && (int) $program->id === $currentId,
            ])
            ->values()
            ->all();
    }

    private function resolveFeeItem(): ?FeeItem
    {
        return FeeItem::query()
            ->where('category', 'transcript')
            ->where('is_active', true)
            ->where('amount', '>', 0)
            ->orderBy('display_order')
            ->orderBy('id')
            ->first();
    }

    private function unavailableReason(?FeeItem $fee): ?string
    {
        if (! TranscriptRequestSettings::all()['transcript_requests_enabled']) {
            return 'Official transcript requests are currently closed.';
        }
        if (count(TranscriptRequestSettings::enabledDeliveryModes()) === 0) {
            return 'Official transcript requests are not configured.';
        }
        if (! $fee) {
            return 'The transcript fee has not been set by Finance yet.';
        }

        return null;
    }

    private function storeGeneratedPdf(TranscriptRequest $request, User $staff): string
    {
        $request->loadMissing(['student.program', 'program']);
        $student = $request->student;
        abort_unless($student, 422, 'Student missing.');

        $programId = $request->program_id ? (int) $request->program_id : null;
        $payload = TranscriptBuilder::forStudent($student, true, false, $programId);
        $programmeName = $request->program?->name ?: $student->program?->name;
        $html = view('reports.official-transcript', [
            'report' => [
                'university' => (string) Setting::getValue('university_name', 'Bells University of Technology'),
                'generated_at' => now()->format('d M Y'),
                'cgpa' => $payload['cgpa'] ?? $payload['gpa'] ?? null,
                'total_credits' => $payload['total_credits'] ?? null,
                'terms' => $payload['terms'] ?? [],
                'copies' => $request->copies,
                'request_token' => $request->public_token,
                'signed_by' => $staff->name,
                'student' => [
                    'name' => trim(($student->first_name ?? '').' '.($student->last_name ?? '')),
                    'matric_number' => $student->matric_number,
                    'programme' => $programmeName,
                ],
            ],
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $relative = 'transcripts/'.$request->id.'/official-'.Str::random(8).'.pdf';
        Storage::disk('local')->put($relative, $dompdf->output());

        return $relative;
    }
}
