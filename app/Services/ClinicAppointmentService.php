<?php

namespace App\Services;

use App\Models\ClinicVisit;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicAppointmentService
{
    public const OPEN_STATUSES = ['pending', 'scheduled', 'waiting', 'in_progress'];

    public const QUEUE_STATUSES = ['waiting', 'in_progress', 'completed', 'cancelled'];

    public const REQUEST_STATUSES = ['pending', 'scheduled'];

    public function __construct(
        private AuditWriter $audit,
        private Notifier $notifier,
    ) {}

    public function book(Student $student, Carbon $when, string $complaint): ClinicVisit
    {
        $this->assertBookableSlot($when);

        $open = ClinicVisit::query()
            ->where('student_id', $student->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->exists();
        abort_if($open, 422, 'You already have an open clinic appointment. Cancel it before booking another.');

        $visit = ClinicVisit::query()->create([
            'student_id' => $student->id,
            'status' => 'pending',
            'visit_type' => 'appointment',
            'visited_on' => $when->toDateString(),
            'scheduled_at' => $when,
            'complaint' => $complaint,
            'notes_internal' => true,
        ]);

        $this->audit->record(
            'clinic.appointment_requested',
            'Student requested a clinic appointment',
            'medical',
            'clinic_visit',
            $visit->id,
            null,
            $visit->toArray()
        );
        $this->notifyStudent(
            $student,
            'Clinic appointment requested',
            'Your clinic appointment request has been submitted. Clinic staff must approve it before the visit is confirmed.',
            $visit->id
        );

        return $visit;
    }

    public function cancelByStudent(Student $student, ClinicVisit $visit): ClinicVisit
    {
        abort_unless($visit->student_id === $student->id, 403);
        abort_unless($visit->visit_type === 'appointment', 422, 'Only booked appointments can be cancelled here.');
        abort_unless(in_array($visit->status, ['pending', 'scheduled'], true), 422, 'This appointment can no longer be cancelled.');

        return $this->markCancelled($visit, 'Cancelled by student', 'clinic.appointment_cancelled', 'Student cancelled a clinic appointment');
    }

    public function approve(ClinicVisit $visit): ClinicVisit
    {
        abort_unless($visit->status === 'pending', 422, 'Only pending appointment requests can be approved.');

        $before = $visit->toArray();
        $visit->update(['status' => 'scheduled']);
        $fresh = $visit->fresh(['student.user']);

        $this->audit->record(
            'clinic.appointment_approved',
            'Clinic appointment approved',
            'medical',
            'clinic_visit',
            $visit->id,
            $before,
            $fresh?->toArray()
        );

        $when = $fresh?->scheduled_at?->timezone('Africa/Lagos')->format('d M Y, H:i');
        $this->notifyStudent(
            $fresh?->student,
            'Clinic appointment confirmed',
            $when
                ? "Your clinic appointment is confirmed for {$when}. Come to the clinic at that time."
                : 'Your clinic appointment has been confirmed. Come to the clinic at the scheduled time.',
            $visit->id
        );

        return $fresh ?? $visit;
    }

    public function reject(ClinicVisit $visit, ?string $reason = null): ClinicVisit
    {
        abort_unless($visit->status === 'pending', 422, 'Only pending appointment requests can be rejected.');

        $before = $visit->toArray();
        $note = $reason ? 'Rejected: '.$reason : 'Rejected by clinic staff';
        $visit->update([
            'status' => 'rejected',
            'notes' => $this->appendNote($visit->notes, $note),
        ]);
        $fresh = $visit->fresh(['student.user']);

        $this->audit->record(
            'clinic.appointment_rejected',
            'Clinic appointment rejected',
            'medical',
            'clinic_visit',
            $visit->id,
            $before,
            $fresh?->toArray()
        );
        $this->notifyStudent(
            $fresh?->student,
            'Clinic appointment not approved',
            $reason
                ? 'Your clinic appointment was not approved: '.$reason.' You may book another slot.'
                : 'Your clinic appointment was not approved. You may book another slot.',
            $visit->id
        );

        return $fresh ?? $visit;
    }

    /**
     * @param  array{student_id: int, visit_type?: string, scheduled_at?: mixed, visited_on?: mixed, triage_priority?: int, complaint?: string}  $data
     */
    public function checkIn(array $data, ?int $staffId): ClinicVisit
    {
        $visitedOn = Carbon::parse($data['visited_on'] ?? now()->toDateString(), 'Africa/Lagos')->toDateString();
        $studentId = (int) $data['student_id'];

        return DB::transaction(function () use ($data, $staffId, $visitedOn, $studentId) {
            $existing = ClinicVisit::query()
                ->where('student_id', $studentId)
                ->whereDate('visited_on', $visitedOn)
                ->whereIn('status', ['waiting', 'in_progress'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->setAttribute('reused_appointment', true);

                return $existing->load(['student.medicalProfile', 'bill']);
            }

            $booking = ClinicVisit::query()
                ->where('student_id', $studentId)
                ->whereDate('visited_on', $visitedOn)
                ->whereIn('status', ['scheduled', 'pending'])
                ->lockForUpdate()
                ->orderByRaw("FIELD(status, 'scheduled', 'pending')")
                ->first();

            if ($booking) {
                $before = $booking->toArray();
                $booking->update([
                    'status' => 'waiting',
                    'staff_id' => $staffId ?? $booking->staff_id,
                    'visit_type' => 'appointment',
                    'triage_priority' => $data['triage_priority'] ?? $booking->triage_priority,
                    'complaint' => $data['complaint'] ?? $booking->complaint,
                ]);
                $fresh = $booking->fresh(['student.medicalProfile', 'bill']);
                $this->audit->record(
                    'clinic.visit_checked_in',
                    'Student checked into clinic queue against existing appointment',
                    'medical',
                    'clinic_visit',
                    $booking->id,
                    $before,
                    $fresh?->toArray()
                );
                $fresh?->setAttribute('reused_appointment', true);

                return $fresh ?? $booking;
            }

            $visit = ClinicVisit::query()->create([
                'student_id' => $studentId,
                'staff_id' => $staffId,
                'status' => 'waiting',
                'visit_type' => $data['visit_type'] ?? 'walk_in',
                'visited_on' => $visitedOn,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'triage_priority' => $data['triage_priority'] ?? null,
                'complaint' => $data['complaint'] ?? null,
                'notes_internal' => true,
            ]);
            $this->audit->record(
                'clinic.visit_checked_in',
                'Student checked into clinic queue',
                'medical',
                'clinic_visit',
                $visit->id,
                null,
                $visit->toArray()
            );

            return $visit->load(['student.medicalProfile', 'bill']);
        });
    }

    public function listAppointments(?string $status = null, ?string $date = null): Collection
    {
        return ClinicVisit::query()
            ->with(['student.medicalProfile'])
            ->where('visit_type', 'appointment')
            ->when(
                $status,
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->whereIn('status', self::REQUEST_STATUSES),
            )
            ->when($date, fn ($query, string $day) => $query->whereDate('visited_on', $day))
            ->orderByRaw("FIELD(status, 'pending', 'scheduled')")
            ->orderBy('scheduled_at')
            ->get();
    }

    public function cancelNoShows(Carbon $today): int
    {
        $cutoff = $today->copy()->timezone('Africa/Lagos')->toDateString();
        $visits = ClinicVisit::query()
            ->with('student.user')
            ->whereIn('status', ['pending', 'scheduled'])
            ->whereDate('visited_on', '<', $cutoff)
            ->get();

        foreach ($visits as $visit) {
            $this->markCancelled(
                $visit,
                'Auto-cancelled: student did not arrive',
                'clinic.appointment_no_show',
                'Clinic appointment auto-cancelled because the student did not attend',
                true,
            );
        }

        return $visits->count();
    }

    public function assertStatusTransition(ClinicVisit $visit, string $next): void
    {
        $allowed = match ($visit->status) {
            'pending' => ['pending', 'scheduled', 'rejected', 'cancelled', 'waiting'],
            'scheduled' => ['scheduled', 'waiting', 'cancelled'],
            'waiting' => ['waiting', 'in_progress', 'completed', 'cancelled'],
            'in_progress' => ['in_progress', 'completed', 'cancelled'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
            'rejected' => ['rejected'],
            default => ['waiting', 'in_progress', 'completed', 'cancelled'],
        };

        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'That status change is not allowed for this visit.',
            ]);
        }
    }

    public function assertBookableSlot(Carbon $when): void
    {
        if ($when->lt(now('Africa/Lagos'))) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'Choose a future date and time.',
            ]);
        }
    }

    private function markCancelled(
        ClinicVisit $visit,
        string $note,
        string $auditAction,
        string $auditSummary,
        bool $notifyStudent = false,
    ): ClinicVisit {
        $before = $visit->toArray();
        $visit->update([
            'status' => 'cancelled',
            'notes' => $this->appendNote($visit->notes, $note),
        ]);
        $fresh = $visit->fresh(['student.user']);
        $this->audit->record(
            $auditAction,
            $auditSummary,
            'medical',
            'clinic_visit',
            $visit->id,
            $before,
            $fresh?->toArray()
        );
        if ($notifyStudent) {
            $this->notifyStudent(
                $fresh?->student,
                'Clinic appointment cancelled',
                'Your clinic appointment was cancelled because you did not attend. You may book another slot.',
                $visit->id
            );
        }

        return $fresh ?? $visit;
    }

    private function appendNote(?string $existing, string $line): string
    {
        $existing = trim((string) $existing);

        return $existing === '' ? $line : $existing."\n".$line;
    }

    private function notifyStudent(?Student $student, string $title, string $body, int $visitId): void
    {
        $student?->loadMissing('user');
        if ($student?->user) {
            $this->notifier->send($student->user, 'clinic', $title, $body, 'medical', $visitId);
        }
    }
}
