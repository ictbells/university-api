<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;

class ReceiptPayer
{
    /**
     * @return array{
     *     name: string,
     *     id: ?string,
     *     id_label: string,
     *     programme: ?string,
     *     level: ?string
     * }
     */
    public static function forInvoice(Invoice $invoice, ?Payment $payment = null): array
    {
        $invoice->loadMissing([
            'user.student.program',
            'user.latestApplication.program',
            'user.latestApplication.steps',
            'student.program',
            'student.application.program',
            'student.application.steps',
            'application.program',
            'application.steps',
            'application.user.student.program',
        ]);

        $student = self::resolveStudent($invoice, $payment);
        $user = $invoice->user ?: $payment?->user ?: $student?->user;
        $application = self::resolveApplication($invoice, $student, $user);
        $category = strtolower((string) $invoice->category);
        $showAcademic = $category !== 'application_fee';

        return [
            'name' => self::name($user, $student, $invoice),
            ...self::identity($student, $application, $user),
            'programme' => $showAcademic ? self::programme($student, $application) : null,
            'level' => $showAcademic ? self::level($student, $invoice, $application) : null,
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     id: ?string,
     *     id_label: string,
     *     programme: ?string,
     *     level: ?string
     * }
     */
    public static function forPayment(Payment $payment): array
    {
        $payment->loadMissing([
            'user.student.program',
            'invoice.user.student.program',
            'invoice.student.program',
            'invoice.application.program',
            'invoice.application.steps',
        ]);

        if ($payment->invoice) {
            return self::forInvoice($payment->invoice, $payment);
        }

        $student = $payment->user?->student;

        return [
            'name' => self::name($payment->user, $student, null),
            ...self::identity($student, null, $payment->user),
            'programme' => self::programme($student, null),
            'level' => self::level($student, null, null),
        ];
    }

    private static function resolveStudent(Invoice $invoice, ?Payment $payment): ?Student
    {
        $student = $invoice->student
            ?: $invoice->user?->student
            ?: $payment?->user?->student;

        if (! $student && $invoice->user_id) {
            $student = Student::query()
                ->with(['program', 'application.program', 'application.steps', 'programmeChanges'])
                ->where('user_id', $invoice->user_id)
                ->first();
        }

        if ($student) {
            $student->loadMissing(['program', 'application.program', 'application.steps', 'programmeChanges']);
        }

        return $student;
    }

    private static function resolveApplication(Invoice $invoice, ?Student $student, ?User $user): ?Application
    {
        $application = $invoice->application
            ?: $student?->application
            ?: $user?->latestApplication;

        if (! $application && $user?->id) {
            $application = Application::query()
                ->with(['program', 'steps'])
                ->where('user_id', $user->id)
                ->latest('id')
                ->first();
        }

        if ($application) {
            $application->loadMissing(['program', 'steps']);
        }

        return $application;
    }

    private static function name(?User $user, ?Student $student, ?Invoice $invoice): string
    {
        return $invoice?->user?->name
            ?: $user?->name
            ?: trim(implode(' ', array_filter([$student?->first_name, $student?->last_name])))
            ?: '—';
    }

    /**
     * Prefer matric for returning / existing students; fall back to application number, then JAMB.
     *
     * @return array{id: ?string, id_label: string}
     */
    private static function identity(?Student $student, ?Application $application, ?User $user): array
    {
        $matric = trim((string) ($student?->matric_number ?: $student?->student_number ?: ''));
        if ($matric !== '') {
            return ['id' => $matric, 'id_label' => 'Matric number'];
        }

        $applicationNumber = trim((string) ($application?->application_number ?: ''));
        if ($applicationNumber !== '') {
            return ['id' => $applicationNumber, 'id_label' => 'Application number'];
        }

        $jamb = trim((string) (
            $application?->jamb_registration
            ?: $user?->jamb_registration
            ?: ''
        ));
        if ($jamb !== '') {
            return ['id' => $jamb, 'id_label' => 'JAMB number'];
        }

        return ['id' => null, 'id_label' => 'Matric number'];
    }

    private static function programme(?Student $student, ?Application $application): ?string
    {
        if ($student) {
            $student->loadMissing(['program', 'application.program', 'application.steps']);
        }
        if ($application) {
            $application->loadMissing(['program', 'steps']);
        }

        $latestChangeProgramId = $student?->programmeChanges?->last()?->to_program_id;

        foreach ([
            $student?->program_id,
            $application?->program_id,
            $student?->application?->program_id,
            $latestChangeProgramId,
            $student?->application ? ProgrammeEligibility::firstChoiceId($student->application) : null,
            $application ? ProgrammeEligibility::firstChoiceId($application) : null,
        ] as $programId) {
            $name = self::programmeNameById($programId ? (int) $programId : null);
            if ($name) {
                return $name;
            }
        }

        $fromRelation = trim((string) (
            $student?->program?->name
            ?: $application?->program?->name
            ?: $student?->application?->program?->name
            ?: ''
        ));

        return $fromRelation !== '' ? $fromRelation : null;
    }

    private static function programmeNameById(?int $programId): ?string
    {
        if (! $programId) {
            return null;
        }

        // Soft-deleted programmes must still print on historical receipts.
        $name = Program::withTrashed()->whereKey($programId)->value('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    private static function level(?Student $student, ?Invoice $invoice, ?Application $application): ?string
    {
        $formatted = self::formatLevel($invoice?->level_code ?? $student?->current_level);
        if ($formatted) {
            return $formatted;
        }

        // Acceptance is paid before matriculation — use the admission entry level.
        if ($application && strtolower((string) ($invoice?->category ?? '')) === 'acceptance_fee') {
            return self::applicationEntryLevel($application);
        }

        return null;
    }

    private static function applicationEntryLevel(Application $application): string
    {
        $mode = strtolower((string) $application->entry_mode);
        if ($mode === 'transfer') {
            $assessed = ProgrammeEligibility::step($application, 'credit_assessment')['approved_entry_level'] ?? null;

            return self::formatLevel($assessed) ?: '200 Level';
        }
        if ($mode === 'de') {
            $requested = ProgrammeEligibility::step($application, 'direct_entry')['requested_entry_level'] ?? null;

            return self::formatLevel($requested) ?: '200 Level';
        }
        if ($mode === 'pg') {
            return '1';
        }

        return '100 Level';
    }

    private static function formatLevel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $n = (int) $value;
            if ($n <= 0) {
                return null;
            }
            // Undergraduate bands are stored as 100/200/…; PG may use 1–5.
            if ($n < 100) {
                return (string) $n;
            }

            return $n.' Level';
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{3})\s*L(?:evel)?$/i', $text, $match)) {
            return ((int) $match[1]).' Level';
        }
        if (preg_match('/^\d+$/', $text)) {
            return self::formatLevel((int) $text);
        }

        return $text;
    }
}
