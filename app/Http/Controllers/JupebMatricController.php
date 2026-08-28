<?php

namespace App\Http\Controllers;

use App\Services\JupebMatricAssignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JupebMatricController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(private JupebMatricAssignService $matric) {}

    public function pending(Request $request): JsonResponse
    {
        $this->authorizeMatric($request);

        return response()->json(['data' => $this->matric->pending()]);
    }

    public function template(Request $request): StreamedResponse
    {
        $this->authorizeMatric($request);

        return $this->matric->template();
    }

    public function assign(Request $request): JsonResponse
    {
        $this->authorizeMatric($request);
        $data = $request->validate([
            'student_id' => 'nullable|integer|exists:students,id',
            'application_number' => 'nullable|string|max:40',
            'student_number' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:190',
            'nin' => 'nullable|string|max:20',
            'matric_number' => 'required|string|max:40',
        ]);

        return $this->officeGate(
            'jupeb.matric.assign',
            null,
            $data,
            'Assign JUPEB matric number',
            function () use ($data) {
                try {
                    $result = $this->matric->assign($data['matric_number'], $data);
                } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }

                return response()->json([
                    'message' => $result['created'] ? 'Matric number assigned.' : 'This student already has that matric number.',
                    'data' => $result['student'],
                ]);
            },
        );
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorizeMatric($request);
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);
        $payload = $this->persistApprovalUpload($request);

        return $this->officeGate(
            'jupeb.matric.import',
            null,
            $payload,
            'Import JUPEB matric numbers',
            function () use ($request) {
                try {
                    $result = $this->matric->import($request->file('file'));
                } catch (\InvalidArgumentException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }

                return response()->json([
                    'message' => "Assigned {$result['assigned']} matric number(s). {$result['skipped']} row(s) skipped.",
                    'data' => $result,
                ]);
            },
        );
    }

    private function authorizeMatric(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('admissions.matriculate')
            || $user->hasPermission('students.manage'),
            403,
        );
    }
}
