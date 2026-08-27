<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\ClinicVisit;
use App\Models\HostelAllocation;
use App\Models\Invoice;
use App\Models\MedicalBill;
use App\Models\Setting;
use App\Models\Student;
use App\Support\ExamClearanceSettings;
use App\Support\InstitutionLogo;
use App\Support\TuitionProgress;

class ExamClearanceService
{
    public function __construct(private CourseRegistrationService $registration) {}

    public function forStudent(Student $student): array
    {
        $settings = ExamClearanceSettings::all();
        $checks = [];

        if ($settings['tuition_paid']) {
            $percent = TuitionProgress::currentSessionPercent($student);
            $required = (int) $settings['tuition_percent'];
            $ok = $percent >= $required;
            $checks[] = $this->check(
                'tuition_paid',
                "Tuition paid ({$required}%)",
                $ok,
                $ok ? "Paid {$percent}% of billed tuition." : "Paid {$percent}% of billed tuition; {$required}% is required."
            );
        }

        if ($settings['courses_registered']) {
            $term = $this->registration->currentTerm();
            $roster = $this->registration->rosterStatusFor($student, $term);
            $ok = ($roster['status'] ?? null) === 'registered';
            $termLabel = $term?->name ?: 'the current semester';
            $checks[] = $this->check(
                'courses_registered',
                'Course registration complete',
                $ok,
                $ok
                    ? "Registered for {$termLabel} (".(int) ($roster['enrolled_units'] ?? 0).' units).'
                    : ($term ? "Course registration for {$termLabel} is not complete." : 'No current semester is set.')
            );
        }

        if ($settings['no_outstanding_invoices']) {
            $outstanding = $this->outstandingAmount($student);
            $ok = $outstanding <= 0;
            $checks[] = $this->check(
                'no_outstanding_invoices',
                'No outstanding invoices',
                $ok,
                $ok ? 'No unpaid invoice balance.' : 'Outstanding balance ₦'.number_format($outstanding, 2).'.'
            );
        }

        if ($settings['hostel_if_allocated']) {
            $allocated = HostelAllocation::query()
                ->where('student_id', $student->id)
                ->whereIn('status', ['allocated', 'pending'])
                ->exists();
            if ($allocated) {
                $due = $this->outstandingAmount($student, 'hostel');
                $ok = $due <= 0;
                $checks[] = $this->check(
                    'hostel_if_allocated',
                    'Hostel fees (if allocated)',
                    $ok,
                    $ok ? 'Hostel charges for the allocated bed are settled.' : 'Hostel balance ₦'.number_format($due, 2).'.'
                );
            } else {
                $checks[] = $this->check(
                    'hostel_if_allocated',
                    'Hostel fees (if allocated)',
                    true,
                    'No active hostel allocation; this check does not apply.'
                );
            }
        }

        if ($settings['clinic_bills_cleared']) {
            $due = $this->clinicOutstanding($student);
            $ok = $due <= 0;
            $checks[] = $this->check(
                'clinic_bills_cleared',
                'Clinic bills cleared',
                $ok,
                $ok ? 'No unpaid clinic bills.' : 'Unpaid clinic bills ₦'.number_format($due, 2).'.'
            );
        }

        $cleared = $checks === [] || collect($checks)->every(fn (array $row) => $row['passed']);
        $term = $this->registration->currentTerm();

        return [
            'cleared' => $cleared,
            'status' => $cleared ? 'cleared' : 'not_cleared',
            'settings' => $settings,
            'checks' => $checks,
            'term' => $term ? [
                'id' => $term->id,
                'name' => $term->name,
                'session_label' => $term->session?->label ?: $term->session_label,
            ] : null,
        ];
    }

    public function printHtml(Student $student): string
    {
        $student->loadMissing(['program', 'user']);
        $payload = $this->forStudent($student);
        $name = trim(implode(' ', array_filter([
            $student->first_name,
            $student->middle_name,
            $student->last_name,
        ]))) ?: ($student->user?->name ?? 'Student');
        $term = $payload['term'] ?? null;
        $campus = Campus::query()->where('is_active', true)->orderBy('id')->first()
            ?? Campus::query()->orderBy('id')->first();

        return view('documents.exam-clearance', [
            'institution' => [
                'name' => (string) Setting::getValue('university_name', 'Bells University of Technology'),
                'motto' => (string) Setting::getValue('university_motto', 'Chords of Knowledge'),
                'address' => trim(collect([
                    $campus?->address,
                    $campus?->city,
                ])->filter()->implode(', '))
                    ?: 'KM 8, Idiroko Road, Benja Village P.M.B 1015, Ota, Ogun State',
            ],
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'full_name' => $name,
            'matric_number' => $student->matric_number ?: '—',
            'student_number' => $student->student_number ?: '—',
            'programme' => $student->program?->name ?: '—',
            'level' => $student->current_level !== null && $student->current_level !== ''
                ? (string) $student->current_level
                : '—',
            'session' => $term['session_label'] ?? '—',
            'semester' => $term['name'] ?? '—',
            'cleared' => (bool) ($payload['cleared'] ?? false),
            'checks' => $payload['checks'] ?? [],
            'generated_at' => now()->format('d M Y, h:i A'),
        ])->render();
    }

    public function summarize(Student $student): array
    {
        $result = $this->forStudent($student);
        $name = trim(implode(' ', array_filter([
            $student->first_name,
            $student->middle_name,
            $student->last_name,
        ])));

        return [
            'student_id' => $student->id,
            'name' => $name,
            'matric_number' => $student->matric_number,
            'student_number' => $student->student_number,
            'program' => $student->program?->name,
            'cleared' => $result['cleared'],
            'status' => $result['status'],
            'failed' => collect($result['checks'])->where('passed', false)->pluck('label')->values()->all(),
        ];
    }

    /**
     * @return array{key: string, label: string, required: bool, passed: bool, detail: string}
     */
    private function check(string $key, string $label, bool $passed, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'required' => true,
            'passed' => $passed,
            'detail' => $detail,
        ];
    }

    private function outstandingAmount(Student $student, ?string $category = null): float
    {
        $query = Invoice::query()
            ->where(function ($builder) use ($student) {
                $builder->where('student_id', $student->id);
                if ($student->user_id) {
                    $builder->orWhere('user_id', $student->user_id);
                }
            })
            ->whereIn('status', ['unpaid', 'partial'])
            ->where('balance', '>', 0);

        if ($category) {
            $query->where('category', $category);
        } else {
            $query->whereNotIn('category', ['application_fee', 'acceptance_fee']);
        }

        return round((float) $query->sum('balance'), 2);
    }

    private function clinicOutstanding(Student $student): float
    {
        $visitIds = ClinicVisit::query()->where('student_id', $student->id)->pluck('id');
        if ($visitIds->isEmpty()) {
            return 0.0;
        }

        $bills = MedicalBill::query()
            ->whereIn('clinic_visit_id', $visitIds)
            ->whereNotIn('status', ['paid', 'waived', 'cancelled'])
            ->get();

        $total = 0.0;
        foreach ($bills as $bill) {
            if ($bill->invoice_id) {
                $invoice = Invoice::query()->find($bill->invoice_id);
                if ($invoice && in_array($invoice->status, ['unpaid', 'partial'], true)) {
                    $total += (float) $invoice->balance;
                }
            } else {
                $total += (float) ($bill->student_payable_amount ?: $bill->amount);
            }
        }

        return round($total, 2);
    }
}
