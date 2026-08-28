<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;
use App\Support\ApplicationListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApplicationPhysicalClearanceService
{
    public function __construct(
        private StudentCreationService $students,
        private AuditWriter $audit,
        private Notifier $notifier,
    ) {}

    public function pendingQuery(Request $request)
    {
        $query = ApplicationListQuery::fromRequest($request)
            ->whereNull('physically_cleared_at')
            ->whereNotIn('stage', ['rejected', 'withdrawn'])
            ->whereHas('acceptanceFeeInvoice', fn ($invoice) => $invoice->where('status', 'paid'));

        $query->with(['physicallyClearedBy']);

        return $query;
    }

    public function clearedQuery(Request $request)
    {
        return ApplicationListQuery::fromRequest($request)
            ->whereNotNull('physically_cleared_at')
            ->with(['physicallyClearedBy']);
    }

    public function paginate(Request $request): LengthAwarePaginator
    {
        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $status = (string) $request->input('status', 'pending');
        $query = $status === 'cleared' ? $this->clearedQuery($request) : $this->pendingQuery($request);

        return $query->paginate($perPage);
    }

    public function summarize(Request $request): array
    {
        return [
            'pending' => (int) $this->pendingQuery($request)->reorder()->count(),
            'cleared' => (int) $this->clearedQuery($request)->reorder()->count(),
        ];
    }

    public function eligibilityError(Application $application): ?string
    {
        if ($application->physically_cleared_at) {
            return 'This applicant has already been cleared.';
        }
        if (in_array($application->stage, ['rejected', 'withdrawn'], true)) {
            return 'This application is closed.';
        }
        $application->loadMissing(['acceptanceFeeInvoice', 'user.student']);
        if ($application->acceptanceFeeInvoice?->status !== 'paid') {
            return 'Acceptance fee has not been paid.';
        }
        if (! $application->student_id && ! $application->program_id && ! $application->user?->student) {
            return 'This application has no programme.';
        }

        return null;
    }

    public function clear(Application $application, User $actor): Application
    {
        $error = $this->eligibilityError($application);
        if ($error) {
            throw new RuntimeException($error);
        }

        return DB::transaction(function () use ($application, $actor) {
            $application->update([
                'physically_cleared_at' => now(),
                'physically_cleared_by' => $actor->id,
            ]);

            if (! $application->student_id) {
                $existing = $application->user?->student;
                if ($existing) {
                    $this->students->attachExistingStudent($application->fresh(['user', 'program', 'steps']), $existing);
                } else {
                    $this->students->createFromApplication($application->fresh(['user', 'program', 'steps']));
                }
            }

            $application = $application->fresh([
                'user',
                'program.department.faculty',
                'intake.term',
                'academicSession',
                'acceptanceFeeInvoice',
                'student',
                'physicallyClearedBy',
            ]);

            $this->audit->record(
                'application.physically_cleared',
                'Applicant physically cleared after acceptance payment',
                'admissions',
                'application',
                $application->id,
                ['stage' => 'acceptance_paid'],
                [
                    'stage' => $application->stage,
                    'student_id' => $application->student_id,
                    'physically_cleared_at' => optional($application->physically_cleared_at)?->toIso8601String(),
                ],
            );
            $this->notifier->send(
                $application->user,
                'application_cleared',
                'Physical clearance completed',
                'Your documents have been cleared. Your student record is now active.',
                'admissions',
                $application->id,
            );

            return $application;
        });
    }

    /**
     * @param  list<int>  $ids
     * @return array{cleared_count: int, skipped: list<array{id: int, reason: string}>, applications: list<Application>}
     */
    public function clearMany(array $ids, User $actor): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $cleared = [];
        $skipped = [];

        foreach ($ids as $id) {
            $application = Application::query()->with(['acceptanceFeeInvoice', 'user.student'])->find($id);
            if (! $application) {
                $skipped[] = ['id' => $id, 'reason' => 'Application not found.'];
                continue;
            }
            try {
                $cleared[] = $this->clear($application, $actor);
            } catch (RuntimeException $exception) {
                $skipped[] = ['id' => $id, 'reason' => $exception->getMessage()];
            }
        }

        return [
            'cleared_count' => count($cleared),
            'skipped' => $skipped,
            'applications' => $cleared,
        ];
    }
}
