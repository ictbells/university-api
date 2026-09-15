<?php

namespace App\Http\Controllers;

use App\Support\PgAdmissionSignature;
use App\Support\RegistrarSignature;
use App\Support\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecuritySettingsController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function show(): JsonResponse
    {
        return response()->json(SecuritySettings::all());
    }

    public function uploadRegistrarSignature(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        return $this->officeGate(
            'settings.upload_registrar_signature',
            null,
            $this->persistApprovalUpload($request),
            'Upload registrar signature',
            function () use ($request) {
                $file = $request->file('file');
                abort_unless($file, 422, 'Choose a signature image to upload.');
                RegistrarSignature::store($file);

                return response()->json(SecuritySettings::all());
            },
        );
    }

    public function destroyRegistrarSignature(): JsonResponse
    {
        return $this->officeGate(
            'settings.delete_registrar_signature',
            null,
            [],
            'Remove registrar signature',
            function () {
                RegistrarSignature::delete();

                return response()->json(SecuritySettings::all());
            },
        );
    }

    public function uploadPgSignatorySignature(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        return $this->officeGate(
            'settings.upload_pg_signatory_signature',
            null,
            $this->persistApprovalUpload($request),
            'Upload postgraduate admission signature',
            function () use ($request) {
                $file = $request->file('file');
                abort_unless($file, 422, 'Choose a signature image to upload.');
                PgAdmissionSignature::store($file);

                return response()->json(SecuritySettings::all());
            },
        );
    }

    public function destroyPgSignatorySignature(): JsonResponse
    {
        return $this->officeGate(
            'settings.delete_pg_signatory_signature',
            null,
            [],
            'Remove postgraduate admission signature',
            function () {
                PgAdmissionSignature::delete();

                return response()->json(SecuritySettings::all());
            },
        );
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
            'admissions_email' => 'sometimes|nullable|email|max:255',
            'admissions_phone' => 'sometimes|nullable|string|max:40',
            'staff_support_label' => 'sometimes|nullable|string|max:80',
            'staff_support_email' => 'sometimes|nullable|email|max:255',
            'staff_support_phone' => 'sometimes|nullable|string|max:40',
            'studentship_years_after_graduation' => 'sometimes|integer|min:1|max:10',
            'transcript_requests_enabled' => 'sometimes|boolean',
            'transcript_delivery_collect' => 'sometimes|boolean',
            'transcript_delivery_generated_pdf' => 'sometimes|boolean',
            'transcript_delivery_uploaded_pdf' => 'sometimes|boolean',
            'transcript_collect_instructions' => 'sometimes|nullable|string|max:2000',
            'public_pay_enabled' => 'sometimes|boolean',
            'public_pay_collect_instructions' => 'sometimes|nullable|string|max:2000',
            'registrar_name' => 'sometimes|nullable|string|max:120',
            'registrar_title' => 'sometimes|nullable|string|max:80',
            'pg_signatory_name' => 'sometimes|nullable|string|max:120',
            'pg_signatory_title' => 'sometimes|nullable|string|max:120',
            'pg_research_interest_min_words' => 'sometimes|integer|min:0|max:5000',
            'pg_research_interest_max_words' => 'sometimes|integer|min:0|max:5000',
            'pg_statement_of_purpose_min_words' => 'sometimes|integer|min:0|max:5000',
            'pg_statement_of_purpose_max_words' => 'sometimes|integer|min:0|max:5000',
            'payment_gateway' => 'sometimes|in:paystack,wema,paygate',
        ]);

        return $this->officeGate('settings.update', null, $data, 'Update application settings', function () use ($data) {
            try {
                return response()->json(SecuritySettings::update($data));
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    }
}
