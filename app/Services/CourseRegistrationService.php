<?php

namespace App\Services;

use App\Models\AcademicLevel;
use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\RegistrationExtension;
use App\Models\Student;
use App\Models\UnitGrace;
use App\Models\UnitLimit;
use App\Models\User;
use App\Support\TuitionProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseRegistrationService
{
    public const BUCKETS = ['general', 'faculty', 'departmental', 'overall'];

    public function __construct(
        private InvoiceService $invoices,
        private AuditWriter $audit,
        private Notifier $notifier,
        private WorkflowEngine $workflows,
    ) {}

    public function currentTerm(): ?AcademicTerm
    {
        return AcademicTerm::current();
    }

    public function context(Student $student, ?AcademicTerm $term = null, bool $ensureCarryOvers = true, bool $forStaff = false): array
    {
        $student->loadMissing(['program.department.faculty', 'user']);
        $term ??= $this->currentTerm();
        if (! $term) {
            return [
                'term' => null,
                'window' => 'Closed',
                'can_self_register' => false,
                'cannot_register_reason' => 'No academic semester is current.',
                'tuition_percent' => TuitionProgress::percentPaid($student),
                'tuition_ok' => false,
                'limits' => [],
                'units' => ['general' => 0, 'faculty' => 0, 'departmental' => 0, 'overall' => 0],
                'roster_status' => 'not_started',
                'extension' => null,
                'enrollments' => [],
                'available' => [],
                'carry_overs' => [],
            ];
        }

        if ($ensureCarryOvers) {
            $this->ensureCarryOvers($student, $term);
        }

        $extension = $this->activeExtension($student, $term);
        $limits = $this->resolvedLimits($student, $term);
        $enrolled = $this->enrolledRows($student, $term);
        $units = $this->unitsByBucket($enrolled);
        $canSelfRegister = $this->studentCanMutate($student, $term, $extension, false);
        $blockReason = $canSelfRegister ? null : $this->studentMutateBlockReason($student, $term, $extension);

        return [
            'student' => [
                'id' => $student->id,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'matric_number' => $student->matric_number,
                'student_number' => $student->student_number,
                'current_level' => $student->current_level,
                'program' => $student->program?->only(['id', 'name', 'code']),
            ],
            'term' => [
                'id' => $term->id,
                'name' => $term->name,
                'session_label' => $term->session?->label ?: $term->session_label,
                'registration_status' => $term->registrationStatus(),
                'normal_registration_closes_at' => optional($term->normal_registration_closes_at)?->toIso8601String(),
                'late_registration_closes_at' => optional($term->late_registration_closes_at)?->toIso8601String(),
                'extension_price_per_unit' => $term->extension_price_per_unit !== null
                    ? (float) $term->extension_price_per_unit
                    : null,
            ],
            'window' => $term->registrationStatus(),
            'tuition_percent' => TuitionProgress::percentPaid($student),
            'tuition_ok' => TuitionProgress::meetsMinimum($student),
            'can_self_register' => $canSelfRegister,
            'cannot_register_reason' => $blockReason,
            'limits' => $limits,
            'units' => $units,
            'roster_status' => $this->rosterStatus($units, $limits),
            'extension' => $extension ? $this->serializeExtension($extension) : null,
            'enrollments' => $enrolled->map(fn (Enrollment $row) => $this->serializeEnrollment($row))->values(),
            'available' => $this->availableOfferings($student, $term),
            'carry_overs' => $enrolled->where('is_carry_over', true)
                ->map(fn (Enrollment $row) => $this->serializeEnrollment($row))
                ->values(),
        ];
    }

    public function availableOfferings(Student $student, AcademicTerm $term): Collection
    {
        $enrolledIds = $this->enrolledRows($student, $term)->pluck('course_offering_id');
        $level = $this->studentLevel($student);

        return CourseOffering::query()
            ->with(['course.department.faculty', 'course.programs', 'lecturer.user'])
            ->withCount(['enrollments as enrolled_count' => fn ($query) => $query->enrolled()])
            ->where('academic_term_id', $term->id)
            ->whereNotIn('id', $enrolledIds)
            ->get()
            ->filter(fn (CourseOffering $offering) => $this->isVisibleToStudent($student, $offering, $level))
            ->map(function (CourseOffering $offering) {
                $taken = (int) ($offering->enrolled_count ?? $offering->enrolledCount());

                return [
                    'id' => $offering->id,
                    'section' => $offering->section,
                    'capacity' => $offering->hasUnlimitedCapacity() ? null : (int) $offering->capacity,
                    'taken' => $taken,
                    'seats_left' => $offering->seatsLeft($taken),
                    'unlimited' => $offering->hasUnlimitedCapacity(),
                    'bucket' => $offering->course?->course_type ?: 'departmental',
                    'required' => ($offering->course?->status ?: 'core') === 'required',
                    'course' => [
                        'id' => $offering->course?->id,
                        'code' => $offering->course?->code,
                        'title' => $offering->course?->title,
                        'units' => (int) $offering->course?->units,
                        'course_type' => $offering->course?->course_type,
                        'status' => $offering->course?->status ?: 'core',
                    ],
                    'lecturer' => $offering->lecturer_display_name,
                ];
            })
            ->values();
    }

    public function register(
        Student $student,
        CourseOffering $offering,
        User $actor,
        bool $asStaff = false,
        ?string $reason = null,
    ): Enrollment {
        $student->loadMissing(['program.department.faculty', 'user']);
        $offering->loadMissing(['course.department.faculty', 'term']);
        $term = $offering->term ?: $this->currentTerm();
        abort_unless($term, 404, 'No academic semester is current.');

        $extension = $this->activeExtension($student, $term);
        if (! $asStaff) {
            $this->assertStudentMayMutate($student, $term, $extension);
            if (! $this->isVisibleToStudent($student, $offering, $this->studentLevel($student))) {
                $this->fail('offering', 'This course is not available for your programme.');
            }
        } else {
            $this->assertStaffOverride($term, $reason, $student);
        }

        $existing = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('course_offering_id', $offering->id)
            ->first();
        if ($existing && $existing->status === 'enrolled') {
            $this->fail('offering', 'Already registered for this course.');
        }
        if ($offering->isFull()) {
            $this->fail('offering', 'This offering has no seats left.');
        }

        $enrolled = $this->enrolledRows($student, $term);
        $this->assertMaxUnits(
            $student,
            $term,
            $enrolled,
            (int) $offering->course->units,
            $offering->course->course_type ?: 'departmental',
            $extension,
        );

        $enrollment = DB::transaction(function () use ($existing, $student, $offering, $actor) {
            if ($existing) {
                $existing->update([
                    'status' => 'enrolled',
                    'registered_at' => now(),
                    'dropped_at' => null,
                    'drop_reason' => null,
                    'registered_by' => $actor->id,
                ]);

                return $existing->fresh(['offering.course', 'grade']);
            }

            return Enrollment::query()->create([
                'student_id' => $student->id,
                'course_offering_id' => $offering->id,
                'status' => 'enrolled',
                'registered_at' => now(),
                'registered_by' => $actor->id,
                'is_carry_over' => false,
            ])->load(['offering.course', 'grade']);
        });

        $this->audit->record(
            'enrollment.registered',
            'Course registered',
            'academic',
            'enrollment',
            $enrollment->id,
            null,
            [
                'student_id' => $student->id,
                'offering_id' => $offering->id,
                'course' => $offering->course?->code,
                'staff' => $asStaff,
                'reason' => $reason,
                'tuition_percent' => TuitionProgress::percentPaid($student),
            ],
            $reason,
            $actor,
        );

        if ($asStaff && $student->user && $student->user_id !== $actor->id) {
            $this->notifier->send(
                $student->user,
                'enrollment.registered',
                'Course registered',
                ($offering->course?->code ?: 'A course').' was added to your registration.',
                'academic',
                $enrollment->id,
            );
        }

        $context = $this->context($student, $term, ensureCarryOvers: false);
        $this->workflows->completeEnrolmentIfRegistered($student, (string) ($context['roster_status'] ?? ''));

        return $enrollment;
    }

    public function drop(Enrollment $enrollment, User $actor, bool $asStaff = false, ?string $reason = null): Enrollment
    {
        $enrollment->loadMissing(['offering.course', 'offering.term', 'student.user', 'grade']);
        $student = $enrollment->student;
        $term = $enrollment->offering?->term ?: $this->currentTerm();
        abort_unless($student && $term, 404);

        if ($enrollment->status !== 'enrolled') {
            $this->fail('enrollment', 'This course is not currently registered.');
        }
        if ($enrollment->grade) {
            $this->fail('enrollment', 'A graded course cannot be dropped.');
        }

        $extension = $this->activeExtension($student, $term);
        if (! $asStaff) {
            if ($enrollment->is_carry_over) {
                $this->fail('enrollment', 'Carry-over courses cannot be dropped. Ask an academic officer.');
            }
            $this->assertStudentMayMutate($student, $term, $extension);
        } else {
            $this->assertStaffOverride($term, $reason, $student, (bool) $enrollment->is_carry_over);
        }

        $remaining = $this->enrolledRows($student, $term)->reject(fn (Enrollment $row) => $row->id === $enrollment->id);
        $this->assertMinOnDrop($student, $term, $remaining, $enrollment);

        $before = $enrollment->toArray();
        $enrollment->update([
            'status' => 'dropped',
            'dropped_at' => now(),
            'drop_reason' => $reason,
        ]);

        $this->audit->record(
            'enrollment.dropped',
            'Course dropped',
            'academic',
            'enrollment',
            $enrollment->id,
            $before,
            $enrollment,
            $reason,
            $actor,
        );

        if ($asStaff && $student->user && $student->user_id !== $actor->id) {
            $this->notifier->send(
                $student->user,
                'enrollment.dropped',
                'Course dropped',
                ($enrollment->offering?->course?->code ?: 'A course').' was dropped from your registration.',
                'academic',
                $enrollment->id,
            );
        }

        return $enrollment->fresh(['offering.course', 'grade']);
    }

    public function grantGrace(Student $student, AcademicTerm $term, string $bucket, int $extraUnits, string $reason, User $actor): UnitGrace
    {
        if (! in_array($bucket, self::BUCKETS, true)) {
            $this->fail('bucket', 'Choose general, faculty, departmental, or overall.');
        }
        if ($extraUnits < 1) {
            $this->fail('extra_units', 'Grace units must be at least 1.');
        }
        if (trim($reason) === '') {
            $this->fail('reason', 'A reason is required to grant grace units.');
        }

        $grace = UnitGrace::query()->create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'bucket' => $bucket,
            'extra_units' => $extraUnits,
            'reason' => $reason,
            'granted_by' => $actor->id,
        ]);

        $this->audit->record(
            'enrollment.grace_granted',
            'Grace units granted',
            'academic',
            'unit_grace',
            $grace->id,
            null,
            $grace,
            $reason,
            $actor,
        );

        if ($student->user) {
            $this->notifier->send(
                $student->user,
                'enrollment.grace_granted',
                'Extra units granted',
                "You were granted {$extraUnits} extra {$bucket} unit(s) for this semester.",
                'academic',
                $grace->id,
            );
        }

        return $grace;
    }

    public function requestExtension(Student $student, AcademicTerm $term, int $units, string $reason, User $actor): RegistrationExtension
    {
        if ($term->registrationStatus() !== 'Late') {
            $this->fail('term', 'Extension requests are only accepted after normal registration closes.');
        }
        if (! TuitionProgress::meetsMinimum($student)) {
            $this->fail('tuition', 'Pay at least 25% of current-session tuition before requesting an extension.');
        }
        $existing = RegistrationExtension::query()
            ->where('student_id', $student->id)
            ->where('academic_term_id', $term->id)
            ->whereIn('status', RegistrationExtension::ACTIVE)
            ->first();
        if ($existing) {
            $this->fail('extension', 'You already have an active extension request for this semester.');
        }

        $limits = $this->resolvedLimits($student, $term);
        $max = $limits['overall']['max'] !== null ? (int) $limits['overall']['max'] : $units;
        $min = $limits['overall']['min'] !== null ? (int) $limits['overall']['min'] : 0;
        if ($units < $min || $units > $max) {
            $this->fail('requested_units', "Intended units must be between {$min} and {$max}.");
        }
        if (trim($reason) === '') {
            $this->fail('reason', 'Explain why you need a registration extension.');
        }

        $row = RegistrationExtension::query()->create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'requested_units' => $units,
            'status' => 'pending',
            'reason' => $reason,
            'requested_by' => $actor->id,
            'expires_at' => $term->late_registration_closes_at,
        ]);

        $this->audit->record(
            'registration_extension.requested',
            'Registration extension requested',
            'academic',
            'registration_extension',
            $row->id,
            null,
            $row,
            $reason,
            $actor,
        );

        return $row->load(['invoice', 'student.user', 'term']);
    }

    public function reviewExtension(
        RegistrationExtension $extension,
        string $decision,
        User $actor,
        ?int $approvedUnits = null,
        ?string $staffNote = null,
    ): RegistrationExtension {
        $extension->loadMissing(['student.user', 'term', 'invoice']);
        if ($extension->status !== 'pending') {
            $this->fail('extension', 'Only pending requests can be reviewed.');
        }
        $term = $extension->term;
        abort_unless($term, 404);

        if ($decision === 'reject') {
            $extension->update([
                'status' => 'rejected',
                'staff_note' => $staffNote,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $this->audit->record(
                'registration_extension.rejected',
                'Registration extension rejected',
                'academic',
                'registration_extension',
                $extension->id,
                null,
                $extension,
                $staffNote,
                $actor,
            );
            if ($extension->student?->user) {
                $this->notifier->send(
                    $extension->student->user,
                    'registration_extension.rejected',
                    'Extension request declined',
                    $staffNote ?: 'Your course registration extension was not approved.',
                    'academic',
                    $extension->id,
                );
            }

            return $extension->fresh(['invoice', 'student.user', 'term']);
        }

        $units = $approvedUnits ?? (int) $extension->requested_units;
        $limits = $this->resolvedLimits($extension->student, $term);
        $max = $limits['overall']['max'] !== null ? (int) $limits['overall']['max'] : $units;
        $min = $limits['overall']['min'] !== null ? (int) $limits['overall']['min'] : 0;
        if ($units < $min || $units > $max) {
            $this->fail('approved_units', "Approved units must be between {$min} and {$max}.");
        }
        $rate = (float) $term->extension_price_per_unit;
        if ($rate <= 0) {
            $this->fail('extension_price_per_unit', 'Set an extension price per unit on this semester before approving.');
        }

        $amount = round($units * $rate, 2);
        $user = $extension->student?->user;
        abort_unless($user, 422, 'This student has no login account.');

        $invoice = $this->invoices->createForCharge(
            $user,
            'course_registration_extension',
            $amount,
            sprintf('Course registration extension (%d units × %s)', $units, number_format($rate, 2)),
            $extension->student?->application_id,
            $extension->student_id,
        );

        $extension->update([
            'status' => 'approved',
            'approved_units' => $units,
            'staff_note' => $staffNote,
            'invoice_id' => $invoice->id,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'expires_at' => $term->late_registration_closes_at,
        ]);

        $this->audit->record(
            'registration_extension.approved',
            'Registration extension approved',
            'academic',
            'registration_extension',
            $extension->id,
            null,
            ['units' => $units, 'amount' => $amount, 'invoice_id' => $invoice->id],
            $staffNote,
            $actor,
        );

        $this->notifier->send(
            $user,
            'registration_extension.approved',
            'Extension approved — payment required',
            sprintf('Pay %s to continue course registration until the late window closes.', number_format($amount, 2)),
            'academic',
            $extension->id,
        );

        return $extension->fresh(['invoice', 'student.user', 'term']);
    }

    public function markExtensionPaid(Invoice $invoice): void
    {
        if ($invoice->category !== 'course_registration_extension' || $invoice->status !== 'paid') {
            return;
        }

        $extension = RegistrationExtension::query()->where('invoice_id', $invoice->id)->first();
        if (! $extension || in_array($extension->status, ['paid', 'rejected', 'cancelled', 'expired'], true)) {
            return;
        }

        $extension->update(['status' => 'paid', 'paid_at' => now()]);
        $this->audit->record(
            'registration_extension.paid',
            'Registration extension paid',
            'academic',
            'registration_extension',
            $extension->id,
            null,
            $extension,
        );
        if ($extension->student?->user) {
            $this->notifier->send(
                $extension->student->user,
                'registration_extension.paid',
                'Extension payment received',
                'You can now add or drop courses until the extension expires.',
                'academic',
                $extension->id,
            );
        }
    }

    public function ensureCarryOvers(Student $student, AcademicTerm $term): void
    {
        $courseIds = $this->failedCourseIds($student);
        if ($courseIds === []) {
            return;
        }

        $offerings = CourseOffering::query()
            ->with('course')
            ->where('academic_term_id', $term->id)
            ->whereIn('course_id', $courseIds)
            ->get();

        foreach ($offerings as $offering) {
            $row = Enrollment::query()->firstOrNew([
                'student_id' => $student->id,
                'course_offering_id' => $offering->id,
            ]);
            if ($row->exists && $row->status === 'enrolled') {
                if (! $row->is_carry_over) {
                    $row->update(['is_carry_over' => true]);
                }

                continue;
            }
            $row->fill([
                'status' => 'enrolled',
                'registered_at' => $row->registered_at ?: now(),
                'dropped_at' => null,
                'is_carry_over' => true,
            ])->save();
        }
    }

    public function rosterStatusFor(Student $student, ?AcademicTerm $term = null): array
    {
        $term ??= $this->currentTerm();
        if (! $term) {
            return ['status' => 'not_started', 'enrolled_units' => 0, 'extension_status' => null];
        }
        $enrolled = $this->enrolledRows($student, $term);
        $units = $this->unitsByBucket($enrolled);

        return [
            'status' => $this->rosterStatus($units, $this->resolvedLimits($student, $term)),
            'enrolled_units' => $units['overall'],
            'extension_status' => $this->activeExtension($student, $term)?->status,
        ];
    }

    public function studentLevel(Student $student): ?AcademicLevel
    {
        $code = (string) $student->current_level;

        return AcademicLevel::query()
            ->where(function ($query) use ($code) {
                $query->where('code', $code)
                    ->orWhere('code', $code.'L')
                    ->orWhere('name', 'like', $code.'%');
            })
            ->orderBy('sort_order')
            ->first();
    }

    /** @return array<string, array{min: ?int, max: ?int, grace: int}> */
    public function resolvedLimits(Student $student, AcademicTerm $term): array
    {
        $level = $this->studentLevel($student);
        $rows = UnitLimit::query()
            ->where('program_id', $student->program_id)
            ->where(function ($query) use ($level) {
                $query->whereNull('academic_level_id');
                if ($level) {
                    $query->orWhere('academic_level_id', $level->id);
                }
            })
            ->where(function ($query) use ($term) {
                $query->whereNull('academic_term_id')->orWhere('academic_term_id', $term->id);
            })
            ->get();

        $picked = [];
        foreach (self::BUCKETS as $bucket) {
            $row = $rows->where('bucket', $bucket)->sortByDesc(function (UnitLimit $limit) {
                return ($limit->academic_term_id ? 2 : 0) + ($limit->academic_level_id ? 1 : 0);
            })->first();
            $picked[$bucket] = [
                'min' => $row?->min_units,
                'max' => $row?->max_units,
                'grace' => 0,
            ];
        }

        foreach (UnitGrace::query()->where('student_id', $student->id)->where('academic_term_id', $term->id)->get() as $grace) {
            $bucket = $grace->bucket ?: 'overall';
            if (! isset($picked[$bucket])) {
                continue;
            }
            $picked[$bucket]['grace'] += (int) $grace->extra_units;
            if ($picked[$bucket]['max'] !== null) {
                $picked[$bucket]['max'] += (int) $grace->extra_units;
            }
        }

        return $picked;
    }

    private function enrolledRows(Student $student, AcademicTerm $term): Collection
    {
        return Enrollment::query()
            ->with(['offering.course.department.faculty', 'grade'])
            ->where('student_id', $student->id)
            ->enrolled()
            ->whereHas('offering', fn ($query) => $query->where('academic_term_id', $term->id))
            ->get();
    }

    /** @return array{general: int, faculty: int, departmental: int, overall: int} */
    private function unitsByBucket(Collection $enrolled): array
    {
        $units = ['general' => 0, 'faculty' => 0, 'departmental' => 0, 'overall' => 0];
        foreach ($enrolled as $row) {
            $courseUnits = (int) ($row->offering?->course?->units ?? 0);
            $bucket = $row->offering?->course?->course_type ?: 'departmental';
            if (! isset($units[$bucket])) {
                $bucket = 'departmental';
            }
            $units[$bucket] += $courseUnits;
            $units['overall'] += $courseUnits;
        }

        return $units;
    }

    private function rosterStatus(array $units, array $limits): string
    {
        if ($units['overall'] <= 0) {
            return 'not_started';
        }
        foreach (self::BUCKETS as $bucket) {
            $min = $limits[$bucket]['min'] ?? null;
            $max = $limits[$bucket]['max'] ?? null;
            if ($min !== null && $units[$bucket] < $min) {
                return 'in_progress';
            }
            if ($max !== null && $units[$bucket] > $max) {
                return 'in_progress';
            }
        }

        return 'registered';
    }

    private function studentCanMutate(Student $student, AcademicTerm $term, ?RegistrationExtension $extension, bool $throw): bool
    {
        try {
            $this->assertStudentMayMutate($student, $term, $extension);

            return true;
        } catch (ValidationException $e) {
            if ($throw) {
                throw $e;
            }

            return false;
        }
    }

    private function studentMutateBlockReason(Student $student, AcademicTerm $term, ?RegistrationExtension $extension): ?string
    {
        try {
            $this->assertStudentMayMutate($student, $term, $extension);

            return null;
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return is_string($first) ? $first : 'Course registration is not available yet.';
        }
    }

    private function assertStudentMayMutate(Student $student, AcademicTerm $term, ?RegistrationExtension $extension): void
    {
        if (! \App\Support\Studentship::canRegisterCourses($student)) {
            $this->fail('studentship', $student->status === \App\Support\Studentship::STATUS_GRADUATED
                ? 'Graduated students cannot register for a new session.'
                : 'Studentship is not current; course registration is closed.');
        }
        if (! TuitionProgress::meetsMinimum($student)) {
            $this->fail('tuition', 'Pay at least 25% of current-session tuition before registering courses.');
        }
        $window = $term->registrationStatus();
        if ($window === 'Open') {
            return;
        }
        if ($window === 'Late' && $extension?->isPaidActive()) {
            return;
        }
        if ($window === 'Late') {
            $this->fail('window', 'Normal registration is closed. Request a registration extension to continue.');
        }
        $this->fail('window', 'Course registration is closed for this semester.');
    }

    private function assertStaffOverride(AcademicTerm $term, ?string $reason, Student $student, bool $force = false): void
    {
        $needsReason = $force
            || $term->registrationStatus() === 'Closed'
            || ! TuitionProgress::meetsMinimum($student);
        if ($needsReason && ! trim((string) $reason)) {
            $this->fail('reason', 'A reason is required when the window is closed, tuition is below 25%, or a carry-over is dropped.');
        }
    }

    private function assertMaxUnits(
        Student $student,
        AcademicTerm $term,
        Collection $enrolled,
        int $adding,
        string $bucket,
        ?RegistrationExtension $extension,
    ): void {
        $limits = $this->resolvedLimits($student, $term);
        $units = $this->unitsByBucket($enrolled);
        $nextBucket = ($units[$bucket] ?? $units['departmental']) + $adding;
        $nextOverall = $units['overall'] + $adding;
        $bucketMax = $limits[$bucket]['max'] ?? null;
        $overallMax = $limits['overall']['max'] ?? null;
        if ($bucketMax !== null && $nextBucket > $bucketMax) {
            $this->fail('units', "This course would exceed the {$bucket} maximum of {$bucketMax} units.");
        }
        if ($overallMax !== null && $nextOverall > $overallMax) {
            $this->fail('units', "This course would exceed the overall maximum of {$overallMax} units.");
        }
        if ($extension?->isPaidActive() && $extension->approved_units && $nextOverall > (int) $extension->approved_units) {
            $this->fail('units', 'This course would exceed the units approved on your extension.');
        }
    }

    private function assertMinOnDrop(Student $student, AcademicTerm $term, Collection $remaining, Enrollment $dropping): void
    {
        $limits = $this->resolvedLimits($student, $term);
        $before = $this->unitsByBucket($this->enrolledRows($student, $term));
        $after = $this->unitsByBucket($remaining);
        $bucket = $dropping->offering?->course?->course_type ?: 'departmental';
        foreach ([$bucket, 'overall'] as $key) {
            $min = $limits[$key]['min'] ?? null;
            if ($min === null) {
                continue;
            }
            if ($before[$key] >= $min && $after[$key] < $min) {
                $this->fail('units', "Dropping this course would take you below the {$key} minimum of {$min} units.");
            }
        }
    }

    private function isVisibleToStudent(Student $student, CourseOffering $offering, ?AcademicLevel $level): bool
    {
        $course = $offering->course;
        if (! $course) {
            return false;
        }
        $type = $course->course_type ?: 'departmental';
        $program = $student->program;
        if (! $program) {
            return $type === 'general';
        }
        $course->loadMissing('programs');
        $linked = $course->programs->firstWhere('id', $program->id);
        if (! $linked) {
            return false;
        }
        if ($linked->pivot?->academic_level_id && $level && (int) $linked->pivot->academic_level_id !== (int) $level->id) {
            return false;
        }

        return true;
    }

    private function activeExtension(Student $student, AcademicTerm $term): ?RegistrationExtension
    {
        $row = RegistrationExtension::query()
            ->with('invoice')
            ->where('student_id', $student->id)
            ->where('academic_term_id', $term->id)
            ->whereIn('status', RegistrationExtension::ACTIVE)
            ->latest('id')
            ->first();
        if ($row && $row->status === 'paid' && $row->expires_at && $row->expires_at->lessThan(now())) {
            $row->update(['status' => 'expired']);

            return null;
        }

        return $row;
    }

    /** @return list<int> */
    private function failedCourseIds(Student $student): array
    {
        $rows = Enrollment::query()
            ->with(['offering.course', 'grades'])
            ->where('student_id', $student->id)
            ->whereHas('grades')
            ->get();

        $failed = [];
        $passed = [];
        foreach ($rows as $row) {
            $courseId = $row->offering?->course_id;
            if (! $courseId) {
                continue;
            }
            $grades = $row->grades
                ->filter(fn ($g) => \App\Support\GradeStatus::isReleased((string) $g->status))
                ->sortByDesc(fn ($g) => $g->sitting === 'supplementary' ? 1 : 0);
            $grade = $grades->first();
            if (! $grade) {
                continue;
            }
            $isFail = strtoupper((string) $grade->letter) === 'F' || (float) $grade->points === 0.0;
            if ($isFail) {
                $failed[$courseId] = true;
            } else {
                $passed[$courseId] = true;
            }
        }

        return array_values(array_diff(array_keys($failed), array_keys($passed)));
    }

    private function serializeEnrollment(Enrollment $row): array
    {
        return [
            'id' => $row->id,
            'status' => $row->status,
            'is_carry_over' => (bool) $row->is_carry_over,
            'registered_at' => optional($row->registered_at)?->toIso8601String(),
            'course_offering_id' => $row->course_offering_id,
            'bucket' => $row->offering?->course?->course_type ?: 'departmental',
            'offering' => [
                'id' => $row->offering?->id,
                'section' => $row->offering?->section,
                'academic_term_id' => $row->offering?->academic_term_id,
                'course' => [
                    'id' => $row->offering?->course?->id,
                    'code' => $row->offering?->course?->code,
                    'title' => $row->offering?->course?->title,
                    'units' => (int) $row->offering?->course?->units,
                    'course_type' => $row->offering?->course?->course_type,
                    'status' => $row->offering?->course?->status ?: 'core',
                ],
            ],
            'grade' => $row->grade ? [
                'letter' => $row->grade->letter,
                'points' => $row->grade->points,
                'score' => $row->grade->score,
            ] : null,
        ];
    }

    private function serializeExtension(RegistrationExtension $row): array
    {
        return [
            'id' => $row->id,
            'status' => $row->status,
            'requested_units' => (int) $row->requested_units,
            'approved_units' => $row->approved_units !== null ? (int) $row->approved_units : null,
            'reason' => $row->reason,
            'staff_note' => $row->staff_note,
            'expires_at' => optional($row->expires_at)?->toIso8601String(),
            'paid_at' => optional($row->paid_at)?->toIso8601String(),
            'invoice' => $row->invoice ? [
                'id' => $row->invoice->id,
                'number' => $row->invoice->number,
                'amount' => (float) $row->invoice->amount,
                'balance' => (float) $row->invoice->balance,
                'status' => $row->invoice->status,
                'category' => $row->invoice->category,
            ] : null,
        ];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
