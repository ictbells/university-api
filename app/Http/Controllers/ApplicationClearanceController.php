<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Services\ApplicationPhysicalClearanceService;
use Illuminate\Http\Request;
use RuntimeException;

class ApplicationClearanceController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(
        private ApplicationPhysicalClearanceService $clearance,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeView($request);

        $paginator = $this->clearance->paginate($request);
        $payload = $paginator->toArray();
        $payload['summary'] = $this->clearance->summarize($request);
        $payload['data'] = collect($paginator->items())->map(fn (Application $application) => $this->serialize($application))->all();

        return response()->json($payload);
    }

    public function clear(Request $request, Application $application)
    {
        abort_unless($request->user()?->hasPermission('admissions.clear'), 403);

        $error = $this->clearance->eligibilityError($application);
        abort_if($error, 422, $error);

        $navKey = \App\Support\OfficeApprovalCatalog::clearanceNavKey($application->entry_mode);

        return $this->officeGate(
            'admissions.clear',
            $application,
            ['application_id' => $application->id],
            'Clear applicant '.$this->labelFor($application),
            function () use ($application, $request) {
                try {
                    $cleared = $this->clearance->clear($application, $request->user());
                } catch (RuntimeException $exception) {
                    return response()->json(['message' => $exception->getMessage()], 422);
                }

                return response()->json([
                    'message' => 'Applicant cleared.',
                    'data' => $this->serialize($cleared),
                ]);
            },
            $navKey,
        );
    }

    public function bulk(Request $request)
    {
        abort_unless($request->user()?->hasPermission('admissions.clear'), 403);

        $data = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|exists:applications,id',
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $subject = count($ids) === 1 ? Application::query()->find($ids[0]) : null;
        $navChannel = $subject?->entry_mode
            ?? Application::query()->whereIn('id', $ids)->value('entry_mode');

        return $this->officeGate(
            'admissions.clear_bulk',
            $subject,
            ['ids' => $ids],
            count($ids) === 1
                ? 'Clear one admitted applicant'
                : 'Clear '.count($ids).' admitted applicants',
            function () use ($ids, $request) {
                $result = $this->clearance->clearMany($ids, $request->user());
                if ($result['cleared_count'] === 0) {
                    return response()->json([
                        'message' => $result['skipped'][0]['reason'] ?? 'No eligible applicants were cleared.',
                        'cleared_count' => 0,
                        'skipped' => $result['skipped'],
                    ], 422);
                }

                return response()->json([
                    'message' => $result['cleared_count'] === 1
                        ? '1 applicant cleared.'
                        : $result['cleared_count'].' applicants cleared.',
                    'cleared_count' => $result['cleared_count'],
                    'skipped' => $result['skipped'],
                    'data' => collect($result['applications'])->map(fn (Application $application) => $this->serialize($application))->all(),
                ]);
            },
            \App\Support\OfficeApprovalCatalog::clearanceNavKey($navChannel),
        );
    }

    private function authorizeView(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user?->hasPermission('admissions.view') || $user?->hasPermission('admissions.clear'),
            403,
        );
    }

    private function labelFor(Application $application): string
    {
        return $application->application_number
            ?: ($application->user?->name ?: '#'.$application->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Application $application): array
    {
        $application->loadMissing([
            'user',
            'program.department.faculty',
            'intake.term',
            'academicSession',
            'acceptanceFeeInvoice',
            'student',
            'physicallyClearedBy',
        ]);

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'jamb_registration' => $application->jamb_registration ?: $application->user?->jamb_registration,
            'entry_mode' => $application->entry_mode,
            'stage' => $application->stage,
            'offer_reference' => $application->offer_reference,
            'physically_cleared_at' => optional($application->physically_cleared_at)?->toIso8601String(),
            'physically_cleared_by' => $application->physicallyClearedBy?->only(['id', 'name']),
            'user' => $application->user?->only(['id', 'name', 'email', 'jamb_registration']),
            'program' => $application->program ? [
                'id' => $application->program->id,
                'name' => $application->program->name,
                'code' => $application->program->code,
                'department' => $application->program->department?->only(['id', 'name']),
            ] : null,
            'intake' => $application->intake ? [
                'id' => $application->intake->id,
                'name' => $application->intake->name,
                'term' => $application->intake->term?->only(['id', 'session_label']),
            ] : null,
            'academic_session' => $application->academicSession?->only(['id', 'label']),
            'acceptance_fee_invoice' => $application->acceptanceFeeInvoice?->only(['id', 'status', 'amount', 'number']),
            'student' => $application->student?->only(['id', 'matric_number', 'student_number']),
        ];
    }
}
