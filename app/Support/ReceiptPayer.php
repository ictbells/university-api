<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
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
            'application.user.student.program',
        ]);

        $student = self::resolveStudent($invoice, $payment);
        $application = $invoice->application;
        $user = $invoice->user ?: $payment?->user;
        $showAcademic = ! in_array((string) $invoice->category, ['application_fee'], true);

        return [
            'name' => self::name($user, $student, $invoice),
            ...self::identity($student, $application, $user),
            'programme' => $showAcademic ? self::programme($student, $application) : null,
            'level' => $showAcademic ? self::level($student, $invoice) : null,
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
        ]);

        if ($payment->invoice) {
            return self::forInvoice($payment->invoice, $payment);
        }

        $student = $payment->user?->student;

        return [
            'name' => self::name($payment->user, $student, null),
            ...self::identity($student, null, $payment->user),
            'programme' => self::programme($student, null),
            'level' => self::level($student, null),
        ];
    }

    private static function resolveStudent(Invoice $invoice, ?Payment $payment): ?Student
    {
        if ($invoice->student) {
            return $invoice->student;
        }

        $fromUser = $invoice->user?->student ?: $payment?->user?->student;
        if ($fromUser) {
            return $fromUser;
        }

        if ($invoice->user_id) {
            return Student::query()->with('program')->where('user_id', $invoice->user_id)->first();
        }

        return null;
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
        $name = $student?->program?->name ?: $application?->program?->name;

        return $name ? (string) $name : null;
    }

    private static function level(?Student $student, ?Invoice $invoice): ?string
    {
        $level = $invoice?->level_code ?: $student?->current_level;
        $level = is_string($level) ? trim($level) : '';

        return $level !== '' ? $level : null;
    }
}
