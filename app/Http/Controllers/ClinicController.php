<?php

namespace App\Http\Controllers;

use App\Models\ClinicVisit;
use App\Models\ClinicVisitItem;
use App\Models\FeeItem;
use App\Models\Immunization;
use App\Models\Invoice;
use App\Models\MedicalBill;
use App\Models\MedicalProfile;
use App\Models\Prescription;
use App\Models\SickNote;
use App\Services\AuditWriter;
use App\Services\ClinicAppointmentService;
use App\Services\ClinicBillingService;
use App\Support\ClinicSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClinicController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
    public function __construct(
        private ClinicBillingService $billing,
        private AuditWriter $audit,
        private ClinicAppointmentService $appointments,
    ) {}

    public function settings(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('medical.view_any')
            || $request->user()->hasPermission('medical.manage'),
            403
        );

        return ClinicSettings::all();
    }

    public function updateSettings(Request $request)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);
        $data = $request->validate([
            'nhis_enabled' => 'sometimes|boolean',
            'nhis_default_coverage_percent' => 'sometimes|numeric|min:0|max:100',
            'nhis_auto_cover_lines' => 'sometimes|boolean',
        ]);

        return $this->officeGate('medical.update_settings', null, $data, 'Update clinic settings', function () use ($data) {
            $before = ClinicSettings::all();
            $after = ClinicSettings::update($data);
            $this->audit->record('clinic.settings_updated', 'Clinic NHIS settings updated', 'medical', 'setting', null, $before, $after);

            return $after;
        });
    }

    public function queue(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('medical.view_any')
            || $request->user()->hasPermission('medical.manage'),
            403
        );
        $date = $request->query('date', now()->toDateString());

        $visits = ClinicVisit::query()
            ->with(['student.medicalProfile', 'bill'])
            ->whereDate('visited_on', $date)
            ->whereIn('status', ClinicAppointmentService::QUEUE_STATUSES)
            ->orderByRaw("FIELD(status, 'waiting', 'in_progress', 'completed', 'cancelled')")
            ->orderBy('triage_priority')
            ->orderByRaw('COALESCE(scheduled_at, created_at)')
            ->get();

        return [
            'date' => $date,
            'settings' => ClinicSettings::all(),
            'visits' => $visits,
        ];
    }

    public function checkIn(Request $request)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'visit_type' => 'nullable|in:walk_in,appointment',
            'scheduled_at' => 'nullable|date',
            'visited_on' => 'nullable|date',
            'triage_priority' => 'nullable|integer|min:1|max:5',
            'complaint' => 'nullable|string',
        ]);

        return $this->officeGate(
            'medical.check_in',
            null,
            $data,
            'Check in clinic visit',
            fn () => $this->appointments->checkIn($data, $request->user()->staff?->id),
        );
    }

    public function appointments(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('medical.view_any')
            || $request->user()->hasPermission('medical.manage'),
            403
        );
        $filters = $request->validate([
            'status' => 'nullable|in:pending,scheduled,rejected,cancelled',
            'date' => 'nullable|date',
        ]);

        return $this->appointments->listAppointments(
            $filters['status'] ?? null,
            $filters['date'] ?? null,
        );
    }

    public function approveAppointment(Request $request, ClinicVisit $visit)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);

        return $this->officeGate(
            'medical.approve_appointment',
            $visit,
            ['visit_id' => $visit->id],
            'Approve clinic appointment',
            fn () => $this->appointments->approve($visit)->load(['student.medicalProfile']),
        );
    }

    public function rejectAppointment(Request $request, ClinicVisit $visit)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->officeGate(
            'medical.reject_appointment',
            $visit,
            ['visit_id' => $visit->id, ...$data],
            'Reject clinic appointment',
            fn () => $this->appointments->reject($visit, $data['reason'] ?? null)->load(['student.medicalProfile']),
        );
    }

    public function bookAppointment(Request $request)
    {
        $student = $request->user()->student;
        abort_unless($student, 404, 'No student record.');

        $data = $request->validate([
            'scheduled_at' => 'required|date',
            'reason' => 'required|in:consultation,follow_up,nhis_enrolment,immunization,other',
            'complaint' => 'nullable|string|max:500',
        ]);

        $when = Carbon::parse($data['scheduled_at'], 'Africa/Lagos');
        $reasons = [
            'consultation' => 'General consultation',
            'follow_up' => 'Follow-up',
            'nhis_enrolment' => 'NHIS enrolment',
            'immunization' => 'Immunization',
            'other' => 'Other',
        ];
        $reason = $reasons[$data['reason']];
        $details = trim((string) ($data['complaint'] ?? ''));
        $complaint = $details !== '' ? $reason.': '.$details : $reason;

        return $this->appointments->book($student, $when, $complaint);
    }

    public function cancelAppointment(Request $request, ClinicVisit $visit)
    {
        $student = $request->user()->student;
        abort_unless($student, 404, 'No student record.');

        return $this->appointments->cancelByStudent($student, $visit);
    }

    public function updateVisit(Request $request, ClinicVisit $visit)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);
        $data = $request->validate([
            'status' => 'sometimes|in:pending,scheduled,waiting,in_progress,completed,cancelled,rejected',
            'visit_type' => 'sometimes|in:walk_in,appointment',
            'scheduled_at' => 'nullable|date',
            'triage_priority' => 'nullable|integer|min:1|max:5',
            'complaint' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'notes' => 'nullable|string',
            'notes_internal' => 'sometimes|boolean',
            'temperature' => 'nullable|numeric',
            'pulse' => 'nullable|integer',
            'bp_systolic' => 'nullable|integer',
            'bp_diastolic' => 'nullable|integer',
            'weight_kg' => 'nullable|numeric',
            'height_cm' => 'nullable|numeric',
            'disposition' => 'nullable|string',
            'visited_on' => 'sometimes|date',
        ]);
        if (isset($data['status'])) {
            $this->appointments->assertStatusTransition($visit, $data['status']);
        }

        return $this->officeGate(
            'medical.update_visit',
            $visit,
            ['visit_id' => $visit->id, ...$data],
            'Update clinic visit',
            function () use ($request, $visit, $data) {
                $before = $visit->toArray();
                $visit->update($data);
                if (($data['status'] ?? null) === 'in_progress' && ! $visit->staff_id) {
                    $visit->update(['staff_id' => $request->user()->staff?->id]);
                }
                $this->audit->record(
                    'clinic.visit_updated',
                    'Clinic visit updated',
                    'medical',
                    'clinic_visit',
                    $visit->id,
                    $before,
                    $visit->fresh()->toArray()
                );

                return $visit->fresh(['student.medicalProfile', 'items', 'prescriptions', 'sickNotes', 'bill.invoice']);
            },
        );
    }

    public function showVisit(Request $request, ClinicVisit $visit)
    {
        abort_unless(
            $request->user()->hasPermission('medical.view_any')
            || $request->user()->hasPermission('medical.manage'),
            403
        );

        return $visit->load([
            'student.medicalProfile',
            'items',
            'prescriptions',
            'sickNotes',
            'bill.invoice',
        ]);
    }

    public function addItem(Request $request, ClinicVisit $visit)
    {
        abort_unless($request->user()->hasPermission('medical.billing') || $request->user()->hasPermission('medical.manage'), 403);
        abort_if($visit->bill, 422, 'Cannot add items after the bill is finalized.');

        $data = $request->validate([
            'fee_item_id' => [
                'required',
                'integer',
                Rule::exists('fee_items', 'id')->where(fn ($query) => $query
                    ->where('category', 'clinic')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'quantity' => 'nullable|numeric|min:0.01',
            'nhis_covered' => 'nullable|boolean',
            'unit_amount' => 'prohibited',
            'description' => 'prohibited',
        ]);

        return $this->officeGate(
            'medical.add_item',
            $visit,
            ['visit_id' => $visit->id, ...$data],
            'Add clinic bill item',
            function () use ($visit, $data) {
                $fee = FeeItem::query()->findOrFail($data['fee_item_id']);
                $qty = (float) ($data['quantity'] ?? 1);
                $unit = round((float) $fee->amount, 2);
                $profile = MedicalProfile::query()->firstOrCreate(['student_id' => $visit->student_id]);
                $nhisCovered = array_key_exists('nhis_covered', $data)
                    ? (bool) $data['nhis_covered']
                    : ($profile->nhis_enrolled && ClinicSettings::nhisEnabled() && ClinicSettings::nhisAutoCoverLines());

                return ClinicVisitItem::query()->create([
                    'clinic_visit_id' => $visit->id,
                    'fee_item_id' => $fee->id,
                    'description' => $fee->name,
                    'quantity' => $qty,
                    'unit_amount' => $unit,
                    'line_total' => round($qty * $unit, 2),
                    'nhis_covered' => $nhisCovered,
                ]);
            },
        );
    }

    public function updateItem(Request $request, ClinicVisitItem $item)
    {
        abort_unless($request->user()->hasPermission('medical.billing') || $request->user()->hasPermission('medical.manage'), 403);
        abort_if($item->visit?->bill, 422, 'Cannot edit items after the bill is finalized.');

        $data = $request->validate([
            'quantity' => 'sometimes|numeric|min:0.01',
            'nhis_covered' => 'sometimes|boolean',
            'unit_amount' => 'prohibited',
            'description' => 'prohibited',
            'fee_item_id' => 'prohibited',
        ]);

        return $this->officeGate(
            'medical.update_item',
            $item,
            ['item_id' => $item->id, ...$data],
            'Update clinic bill item',
            function () use ($item, $data) {
                $qty = (float) ($data['quantity'] ?? $item->quantity);
                $item->update([
                    'quantity' => $qty,
                    'nhis_covered' => array_key_exists('nhis_covered', $data)
                        ? (bool) $data['nhis_covered']
                        : $item->nhis_covered,
                    'line_total' => round($qty * (float) $item->unit_amount, 2),
                ]);

                return $item->fresh();
            },
        );
    }

    public function deleteItem(Request $request, ClinicVisitItem $item)
    {
        abort_unless($request->user()->hasPermission('medical.billing') || $request->user()->hasPermission('medical.manage'), 403);
        abort_if($item->visit?->bill, 422, 'Cannot remove items after the bill is finalized.');

        return $this->officeGate(
            'medical.delete_item',
            $item,
            ['item_id' => $item->id],
            'Remove clinic bill item',
            function () use ($item) {
                $item->delete();

                return response()->json(['ok' => true]);
            },
        );
    }

    public function finalizeBill(Request $request, ClinicVisit $visit)
    {
        abort_unless($request->user()->hasPermission('medical.billing'), 403);
        $data = $request->validate([
            'coverage_percent_override' => 'nullable|numeric|min:0|max:100',
        ]);

        return $this->officeGate(
            'medical.finalize_bill',
            $visit,
            ['visit_id' => $visit->id, ...$data],
            'Finalize clinic bill',
            function () use ($visit, $data) {
                $bill = $this->billing->finalize(
                    $visit,
                    array_key_exists('coverage_percent_override', $data) ? (float) $data['coverage_percent_override'] : null
                );
                $this->audit->record(
                    'clinic.bill_finalized',
                    'Clinic bill finalized',
                    'medical',
                    'medical_bill',
                    $bill->id,
                    null,
                    $bill->toArray()
                );

                return $bill->load('invoice');
            },
        );
    }

    public function bills(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('medical.view_any')
            || $request->user()->hasPermission('medical.manage')
            || $request->user()->hasPermission('medical.billing'),
            403
        );

        return MedicalBill::query()
            ->with(['visit.student', 'invoice'])
            ->latest()
            ->limit(100)
            ->get();
    }

    public function addPrescription(Request $request, ClinicVisit $visit)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);
        $data = $request->validate([
            'medication' => 'required|string|max:255',
            'dosage' => 'nullable|string|max:255',
            'frequency' => 'nullable|string|max:255',
            'duration' => 'nullable|string|max:255',
            'instructions' => 'nullable|string',
        ]);

        return $this->officeGate(
            'medical.add_prescription',
            $visit,
            ['visit_id' => $visit->id, ...$data],
            'Add prescription',
            fn () => Prescription::query()->create([
                ...$data,
                'clinic_visit_id' => $visit->id,
                'student_id' => $visit->student_id,
                'staff_id' => $request->user()->staff?->id,
            ]),
        );
    }

    public function dispensePrescription(Request $request, Prescription $prescription)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);

        return $this->officeGate(
            'medical.dispense_prescription',
            $prescription,
            ['prescription_id' => $prescription->id],
            'Dispense prescription',
            function () use ($prescription) {
                $prescription->update(['dispensed_at' => now()]);

                return $prescription->fresh();
            },
        );
    }

    public function addSickNote(Request $request, ClinicVisit $visit)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);
        $data = $request->validate([
            'issued_on' => 'nullable|date',
            'valid_from' => 'required|date',
            'valid_to' => 'required|date|after_or_equal:valid_from',
            'reason' => 'required|string',
            'restrictions' => 'nullable|string',
        ]);

        return $this->officeGate(
            'medical.add_sick_note',
            $visit,
            ['visit_id' => $visit->id, ...$data],
            'Issue sick note',
            function () use ($request, $visit, $data) {
                $note = SickNote::query()->create([
                    ...$data,
                    'issued_on' => $data['issued_on'] ?? now()->toDateString(),
                    'clinic_visit_id' => $visit->id,
                    'student_id' => $visit->student_id,
                    'staff_id' => $request->user()->staff?->id,
                ]);
                $this->audit->record(
                    'clinic.sick_note_issued',
                    'Sick note issued',
                    'medical',
                    'sick_note',
                    $note->id,
                    null,
                    $note->toArray()
                );

                return $note;
            },
        );
    }

    public function printSickNote(Request $request, SickNote $sickNote)
    {
        $user = $request->user();
        $student = $sickNote->student;
        $isOwner = $student && $student->user_id === $user->id;
        abort_unless(
            $isOwner
            || $user->hasPermission('medical.view_any')
            || $user->hasPermission('medical.manage'),
            403
        );

        $sickNote->load(['student', 'staff.user']);

        return response()->view('documents.sick-note', [
            'note' => $sickNote,
            'student' => $sickNote->student,
            'staff_name' => $sickNote->staff?->user?->name ?? 'Clinic Officer',
            'issued_on' => optional($sickNote->issued_on)->format('d M Y'),
            'valid_from' => optional($sickNote->valid_from)->format('d M Y'),
            'valid_to' => optional($sickNote->valid_to)->format('d M Y'),
        ]);
    }

    public function me(Request $request)
    {
        $student = $request->user()->student;
        abort_unless($student, 404, 'No student record.');

        $profile = MedicalProfile::query()->firstOrCreate(['student_id' => $student->id]);
        $visits = ClinicVisit::query()
            ->where('student_id', $student->id)
            ->with(['bill.invoice', 'prescriptions', 'sickNotes'])
            ->latest()
            ->get()
            ->map(function (ClinicVisit $visit) {
                $row = $visit->toArray();
                if ($visit->notes_internal) {
                    $row['notes'] = null;
                }
                unset($row['notes_internal']);

                return $row;
            });

        $invoices = Invoice::query()
            ->where('student_id', $student->id)
            ->whereIn('category', ['medical', 'clinic'])
            ->latest()
            ->get();

        $cover = $this->billing->describeCoverage($profile);

        return [
            'profile' => $profile,
            'settings' => [
                'nhis_enabled' => ClinicSettings::nhisEnabled(),
                'nhis_default_coverage_percent' => ClinicSettings::nhisDefaultCoveragePercent(),
            ],
            'effective_coverage_percent' => $cover['percent'],
            'effective_coverage_amount' => $cover['amount'],
            'coverage_mode' => $cover['mode'],
            'immunizations' => Immunization::query()->where('student_id', $student->id)->orderByDesc('given_on')->get(),
            'visits' => $visits,
            'sick_notes' => SickNote::query()->where('student_id', $student->id)->latest()->get(),
            'invoices' => $invoices,
        ];
    }

    public function previewSplit(Request $request, ClinicVisit $visit)
    {
        abort_unless(
            $request->user()->hasPermission('medical.billing')
            || $request->user()->hasPermission('medical.manage'),
            403
        );
        $data = $request->validate([
            'coverage_percent_override' => 'nullable|numeric|min:0|max:100',
        ]);
        $profile = MedicalProfile::query()->firstOrCreate(['student_id' => $visit->student_id]);
        $visit->load('items');

        return $this->billing->splitAmounts(
            $visit,
            $profile,
            array_key_exists('coverage_percent_override', $data) ? (float) $data['coverage_percent_override'] : null
        );
    }
}
