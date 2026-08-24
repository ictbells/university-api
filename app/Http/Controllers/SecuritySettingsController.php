<?php

namespace App\Http\Controllers;

use App\Support\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecuritySettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(SecuritySettings::all());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'two_factor_enabled' => 'sometimes|boolean',
            'password_rotation_days' => 'sometimes|integer|min:0|max:365',
            'inactivity_logout_minutes' => 'sometimes|integer|min:0|max:1440',
            'exam_clearance' => 'sometimes|array',
            'exam_clearance.tuition_paid' => 'sometimes|boolean',
            'exam_clearance.tuition_percent' => 'sometimes|integer|min:0|max:100',
            'exam_clearance.courses_registered' => 'sometimes|boolean',
            'exam_clearance.no_outstanding_invoices' => 'sometimes|boolean',
            'exam_clearance.hostel_if_allocated' => 'sometimes|boolean',
            'exam_clearance.clinic_bills_cleared' => 'sometimes|boolean',
        ]);

        return response()->json(SecuritySettings::update($data));
    }
}
