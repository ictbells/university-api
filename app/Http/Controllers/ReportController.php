<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Enrollment;
use App\Models\HostelBed;
use App\Models\Invoice;
use App\Models\MedicalBill;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Wallet;

class ReportController extends Controller
{
    public function summary()
    {
        $funnel = Application::query()->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');

        return [
            'admissions_funnel' => $funnel,
            'students' => Student::query()->count(),
            'enrollments' => Enrollment::query()->count(),
            'invoices_outstanding' => Invoice::query()->where('status', '!=', 'paid')->sum('balance'),
            'payments_collected' => Payment::query()->where('status', 'successful')->sum('amount'),
            'wallet_total' => Wallet::query()->sum('balance'),
            'hostel_occupancy' => [
                'total_beds' => HostelBed::query()->count(),
                'occupied' => HostelBed::query()->where('status', 'occupied')->count(),
            ],
            'medical_bills' => MedicalBill::query()->sum('amount'),
        ];
    }
}
