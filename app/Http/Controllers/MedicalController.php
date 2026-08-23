<?php

namespace App\Http\Controllers;

use App\Models\ClinicVisit;
use App\Models\FeeItem;
use App\Models\Immunization;
use App\Models\MedicalProfile;
use App\Models\Student;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

class MedicalController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function profile(Request $request, Student $student)
    {
        $this->authorizeMedical($request, $student);

        return [
            'profile' => MedicalProfile::query()->firstOrCreate(['student_id' => $student->id]),
            'immunizations' => Immunization::query()->where('student_id', $student->id)->get(),
            'visits' => ClinicVisit::query()->where('student_id', $student->id)->with('bill')->latest()->get(),
        ];
    }

    public function updateProfile(Request $request, Student $student)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);
        $profile = MedicalProfile::query()->firstOrCreate(['student_id' => $student->id]);
        $profile->update($request->validate([
            'blood_type' => 'nullable|string',
            'allergies' => 'nullable|string',
            'conditions' => 'nullable|string',
        ]));

        return $profile;
    }

    public function addImmunization(Request $request, Student $student)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);

        return Immunization::query()->create($request->validate([
            'vaccine' => 'required|string',
            'given_on' => 'nullable|date',
        ]) + ['student_id' => $student->id]);
    }

    public function visit(Request $request)
    {
        abort_unless($request->user()->hasPermission('medical.manage'), 403);
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'visited_on' => 'required|date',
            'complaint' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'notes' => 'nullable|string',
            'bill_amount' => 'nullable|numeric|min:0',
        ]);
        $visit = ClinicVisit::query()->create([
            ...collect($data)->except('bill_amount')->all(),
            'staff_id' => $request->user()->staff?->id,
        ]);
        if (($data['bill_amount'] ?? 0) > 0) {
            $student = Student::query()->find($data['student_id']);
            $fee = FeeItem::query()->firstOrCreate(
                ['category' => 'medical', 'name' => 'Clinic charge'],
                ['amount' => $data['bill_amount'], 'wallet_allowed' => true, 'is_active' => true]
            );
            $fee->amount = $data['bill_amount'];
            $fee->save();
            $invoice = $this->invoices->createForFee($student->user, $fee, $student->application_id, $student->id);
            $visit->bill()->create([
                'invoice_id' => $invoice->id,
                'amount' => $data['bill_amount'],
                'status' => 'unpaid',
            ]);
        }

        return $visit->load('bill');
    }

    private function authorizeMedical(Request $request, Student $student): void
    {
        $user = $request->user();
        if ($student->user_id === $user->id && $user->hasPermission('medical.view_own')) {
            return;
        }
        if ($user->hasPermission('medical.view_any') || $user->hasPermission('medical.manage')) {
            return;
        }
        abort(403);
    }
}
