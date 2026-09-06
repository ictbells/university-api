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
            'student.program',
            'application.program',
            'application.steps',
            'application.user.student.program',
        ]);

        $student = self::resolveStudent($invoice, $payment);
        $application = $invoice->application;
        $user = $invoice->user ?: $payment?->user;
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
            $student = Student::query()->with('program')->where('user_id', $invoice->user_id)->first();
        }

        if ($student && $student->program_id && ! $student->relationLoaded('program')) {
            $student->load('program');
        }

        return $student;
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
        if ($student?->program_id) {
            $student->loadMissing('program');
        }
        $fromStudent = trim((string) ($student?->program?->name ?? ''));
        if ($fromStudent !== '') {
            return $fromStudent;
        }

        if (! $application) {
            return null;
        }

        $application->loadMissing(['program', 'steps']);
        $fromApplication = trim((string) ($application->program?->name ?? ''));
        if ($fromApplication !== '') {
            return $fromApplication;
        }

        $firstChoiceId = ProgrammeEligibility::firstChoiceId($application);
        if ($firstChoiceId) {
            $name = Program::query()->whereKey($firstChoiceId)->value('name');
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
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
