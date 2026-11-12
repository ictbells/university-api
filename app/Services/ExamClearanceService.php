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
        return $this->evaluateMany([$student])[(int) $student->id]
            ?? [
                'cleared' => true,
                'status' => 'cleared',
                'settings' => ExamClearanceSettings::all(),
                'checks' => [],
                'term' => null,
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
        return $this->summarizeMany([$student])[0] ?? [
            'student_id' => $student->id,
            'name' => trim(implode(' ', array_filter([$student->first_name, $student->middle_name, $student->last_name]))),
            'matric_number' => $student->matric_number,
            'student_number' => $student->student_number,
            'program' => $student->program?->name,
            'cleared' => true,
            'status' => 'cleared',
            'failed' => [],
        ];
    }

    /**
     * @param  iterable<int, Student>  $students
     * @return list<array{
     *   student_id: int,
     *   name: string,
     *   matric_number: ?string,
     *   student_number: ?string,
     *   program: ?string,
     *   cleared: bool,
     *   status: string,
     *   failed: list<string>
     * }>
     */
    public function summarizeMany(iterable $students): array
    {
        $students = collect($students)->values();
        $evaluated = $this->evaluateMany($students);

        return $students->map(function (Student $student) use ($evaluated) {
            $result = $evaluated[(int) $student->id] ?? [
                'cleared' => true,
                'status' => 'cleared',
                'checks' => [],
            ];
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
                'cleared' => (bool) $result['cleared'],
                'status' => (string) $result['status'],
                'failed' => collect($result['checks'] ?? [])->where('passed', false)->pluck('label')->values()->all(),
            ];
        })->all();
    }

    /**
     * @param  iterable<int, Student>  $students
     * @return array<int, array{cleared: bool, status: string, settings: array, checks: list<array>, term: ?array}>
     */
    private function evaluateMany(iterable $students): array
    {
        $students = collect($students)->values();
        $settings = ExamClearanceSettings::all();
        $term = $this->registration->currentTerm();
        $termPayload = $term ? [
            'id' => $term->id,
            'name' => $term->name,
            'session_label' => $term->session?->label ?: $term->session_label,
        ] : null;

        if ($students->isEmpty()) {
            return [];
        }

        $tuition = $settings['tuition_paid']
            ? TuitionProgress::currentSessionPercentsFor($students)
            : [];
        $rosters = $settings['courses_registered']
            ? $this->registration->rosterStatusesFor($students, $term)
            : [];
        $outstanding = ($settings['no_outstanding_invoices'] || $settings['hostel_if_allocated'])
            ? $this->outstandingAmountsFor($students)
            : [];
        $hostelAllocated = $settings['hostel_if_allocated']
            ? $this->hostelAllocatedSet($students)
            : [];
        $clinic = $settings['clinic_bills_cleared']
            ? $this->clinicOutstandingFor($students)
            : [];

        $requiredTuition = (int) $settings['tuition_percent'];
        $termLabel = $term?->name ?: 'the current semester';
        $result = [];

        foreach ($students as $student) {
            $studentId = (int) $student->id;
            $checks = [];

            if ($settings['tuition_paid']) {
                $percent = (float) ($tuition[$studentId] ?? 0.0);
                $ok = $percent >= $requiredTuition;
                $checks[] = $this->check(
                    'tuition_paid',
                    "Tuition paid ({$requiredTuition}%)",
                    $ok,
                    $ok ? "Paid {$percent}% of billed tuition." : "Paid {$percent}% of billed tuition; {$requiredTuition}% is required."
                );
            }

            if ($settings['courses_registered']) {
                $roster = $rosters[$studentId] ?? ['status' => 'not_started', 'enrolled_units' => 0];
                $ok = ($roster['status'] ?? null) === 'registered';
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
                $amount = (float) ($outstanding[$studentId]['general'] ?? 0.0);
                $ok = $amount <= 0;
                $checks[] = $this->check(
                    'no_outstanding_invoices',
                    'No outstanding invoices',
                    $ok,
                    $ok ? 'No unpaid invoice balance.' : 'Outstanding balance ₦'.number_format($amount, 2).'.'
                );
            }

            if ($settings['hostel_if_allocated']) {
                if (isset($hostelAllocated[$studentId])) {
                    $due = (float) ($outstanding[$studentId]['hostel'] ?? 0.0);
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
                $due = (float) ($clinic[$studentId] ?? 0.0);
                $ok = $due <= 0;
                $checks[] = $this->check(
                    'clinic_bills_cleared',
                    'Clinic bills cleared',
                    $ok,
                    $ok ? 'No unpaid clinic bills.' : 'Unpaid clinic bills ₦'.number_format($due, 2).'.'
                );
            }

            $cleared = $checks === [] || collect($checks)->every(fn (array $row) => $row['passed']);
            $result[$studentId] = [
                'cleared' => $cleared,
                'status' => $cleared ? 'cleared' : 'not_cleared',
                'settings' => $settings,
                'checks' => $checks,
                'term' => $termPayload,
            ];
        }

        return $result;
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

    /**
     * @param  iterable<int, Student>  $students
     * @return array<int, array{general: float, hostel: float}>
     */
    private function outstandingAmountsFor(iterable $students): array
    {
        $students = collect($students)->values();
        $studentIds = $students->pluck('id')->map(fn ($id) => (int) $id)->all();
        $userIds = $students->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $studentsByUser = $students->keyBy(fn (Student $student) => (int) ($student->user_id ?: 0));

        $result = [];
        foreach ($studentIds as $studentId) {
            $result[$studentId] = ['general' => 0.0, 'hostel' => 0.0];
        }

        $invoices = Invoice::query()
            ->where(function ($builder) use ($studentIds, $userIds) {
                $builder->whereIn('student_id', $studentIds);
                if ($userIds !== []) {
                    $builder->orWhereIn('user_id', $userIds);
                }
            })
            ->whereIn('status', ['unpaid', 'partial'])
            ->where('balance', '>', 0)
            ->get(['id', 'student_id', 'user_id', 'category', 'balance']);

        foreach ($invoices as $invoice) {
            $studentId = (int) ($invoice->student_id ?: 0);
            if ($studentId <= 0 || ! isset($result[$studentId])) {
                $userId = (int) ($invoice->user_id ?: 0);
                $student = $userId > 0 ? $studentsByUser->get($userId) : null;
                $studentId = $student ? (int) $student->id : 0;
            }
            if ($studentId <= 0 || ! isset($result[$studentId])) {
                continue;
            }

            $balance = (float) $invoice->balance;
            if ($invoice->category === 'hostel') {
                $result[$studentId]['hostel'] += $balance;
            }
            if (! in_array($invoice->category, ['application_fee', 'acceptance_fee'], true)) {
                $result[$studentId]['general'] += $balance;
            }
        }

        foreach ($result as $studentId => $amounts) {
            $result[$studentId]['general'] = round($amounts['general'], 2);
            $result[$studentId]['hostel'] = round($amounts['hostel'], 2);
        }

        return $result;
    }

    /**
     * @param  iterable<int, Student>  $students
     * @return array<int, true>
     */
    private function hostelAllocatedSet(iterable $students): array
    {
        $studentIds = collect($students)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($studentIds === []) {
            return [];
        }

        return HostelAllocation::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', ['allocated', 'pending'])
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->mapWithKeys(fn (int $id) => [$id => true])
            ->all();
    }

    /**
     * @param  iterable<int, Student>  $students
     * @return array<int, float>
     */
    private function clinicOutstandingFor(iterable $students): array
    {
        $students = collect($students)->values();
        $studentIds = $students->pluck('id')->map(fn ($id) => (int) $id)->all();
        $result = array_fill_keys($studentIds, 0.0);
        if ($studentIds === []) {
            return [];
        }

        $visits = ClinicVisit::query()
            ->whereIn('student_id', $studentIds)
            ->get(['id', 'student_id']);
        if ($visits->isEmpty()) {
            return $result;
        }

        $visitStudent = $visits->mapWithKeys(
            fn (ClinicVisit $visit) => [(int) $visit->id => (int) $visit->student_id]
        );
        $bills = MedicalBill::query()
            ->whereIn('clinic_visit_id', $visits->pluck('id')->all())
            ->whereNotIn('status', ['paid', 'waived', 'cancelled'])
            ->get(['id', 'clinic_visit_id', 'invoice_id', 'student_payable_amount', 'amount']);

        $invoiceIds = $bills->pluck('invoice_id')->filter()->unique()->values()->all();
        $invoices = $invoiceIds === []
            ? collect()
            : Invoice::query()->whereIn('id', $invoiceIds)->get()->keyBy('id');

        foreach ($bills as $bill) {
            $studentId = $visitStudent[(int) $bill->clinic_visit_id] ?? 0;
            if ($studentId <= 0 || ! array_key_exists($studentId, $result)) {
                continue;
            }
            if ($bill->invoice_id) {
                $invoice = $invoices->get($bill->invoice_id);
                if ($invoice && in_array($invoice->status, ['unpaid', 'partial'], true)) {
                    $result[$studentId] += (float) $invoice->balance;
                }
            } else {
                $result[$studentId] += (float) ($bill->student_payable_amount ?: $bill->amount);
            }
        }

        foreach ($result as $studentId => $amount) {
            $result[$studentId] = round($amount, 2);
        }

        return $result;
    }
}
