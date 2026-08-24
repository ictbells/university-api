<?php

namespace App\Support;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ApplicationListQuery
{
    public static function fromRequest(Request $request, ?User $user = null, array $except = []): Builder
    {
        $user ??= $request->user();
        $query = Application::query()->with([
            'user',
            'program.department.faculty',
            'program.workflowTemplate.stages',
            'intake.term',
            'applicationFeeInvoice',
            'acceptanceFeeInvoice',
            'steps',
            'refereeInvites',
        ]);

        if ($request->filled('stage') && ! in_array('stage', $except, true)) {
            $stages = array_values(array_filter(array_map('trim', explode(',', (string) $request->stage))));
            if (count($stages) === 1) {
                $query->where('stage', $stages[0]);
            } elseif ($stages !== []) {
                $query->whereIn('stage', $stages);
            }
        }
        if ($request->filled('entry_mode')) {
            $query->where('entry_mode', $request->entry_mode);
        }
        if ($request->filled('entry_modes')) {
            $modes = is_array($request->entry_modes)
                ? $request->entry_modes
                : array_filter(array_map('trim', explode(',', (string) $request->entry_modes)));
            if ($modes !== []) {
                $query->whereIn('entry_mode', $modes);
            }
        }
        if ($request->filled('fee_status')) {
            $feeStatus = (string) $request->fee_status;
            $query->whereHas('applicationFeeInvoice', fn ($invoice) => $invoice->where('status', $feeStatus));
        }
        if ($request->filled('academic_session_id')) {
            $sessionId = (int) $request->academic_session_id;
            $query->whereHas('intake.term', fn ($term) => $term->where('academic_session_id', $sessionId));
        } elseif ($request->filled('academic_term_id')) {
            $termId = (int) $request->academic_term_id;
            $query->whereHas('intake', fn ($intake) => $intake->where('academic_term_id', $termId));
        } elseif ($request->filled('session')) {
            $session = (string) $request->session;
            $query->whereHas('intake.term', fn ($term) => $term->where('session_label', $session));
        }
        if ($request->filled('faculty_id') || $request->filled('college_id')) {
            $facultyId = (int) ($request->input('faculty_id') ?: $request->input('college_id'));
            $query->whereHas('program.department', fn ($department) => $department->where('faculty_id', $facultyId));
        }
        if ($request->filled('department_id')) {
            $query->whereHas('program', fn ($program) => $program->where('department_id', (int) $request->department_id));
        }
        if ($request->filled('program_id')) {
            $query->where('program_id', (int) $request->program_id);
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $like = '%'.$search.'%';
            $query->where(function ($builder) use ($like, $search) {
                $builder->where('application_number', 'like', $like)
                    ->orWhere('jamb_registration', 'like', $like)
                    ->orWhereHas('user', function ($userQuery) use ($like) {
                        $userQuery->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('jamb_registration', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    })
                    ->orWhereHas('program', function ($program) use ($like) {
                        $program->where('name', 'like', $like)
                            ->orWhere('code', 'like', $like);
                    });

                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search);
                }
            });
        }

        if ($user?->hasPermission('admissions.view')) {
            RegistrationCriteria::excludeRegisteredApplications($query);
        } elseif ($user) {
            $query->where('user_id', $user->id);
        }

        return $query->latest();
    }

    /**
     * @return list<string>
     */
    public static function filterSummary(Request $request): array
    {
        $parts = [];
        if ($request->filled('search')) {
            $parts[] = 'Search: '.$request->string('search');
        }
        if ($request->filled('entry_mode')) {
            $parts[] = 'Category: '.strtoupper((string) $request->entry_mode);
        }
        if ($request->filled('faculty_id') || $request->filled('college_id')) {
            $facultyId = (int) ($request->input('faculty_id') ?: $request->input('college_id'));
            $college = Faculty::query()->where('id', $facultyId)->value('name');
            $parts[] = 'College: '.($college ?: '#'.$facultyId);
        }
        if ($request->filled('department_id')) {
            $department = Department::query()->where('id', (int) $request->department_id)->value('name');
            $parts[] = 'Department: '.($department ?: '#'.$request->department_id);
        }
        if ($request->filled('program_id')) {
            $program = Program::query()->where('id', (int) $request->program_id)->first(['name', 'code']);
            $parts[] = 'Programme: '.($program?->name ?: ($program?->code ?: '#'.$request->program_id));
        }
        if ($request->filled('academic_session_id')) {
            $label = AcademicSession::query()->where('id', (int) $request->academic_session_id)->value('label');
            $parts[] = 'Session: '.($label ?: '#'.$request->academic_session_id);
        } elseif ($request->filled('academic_term_id')) {
            $label = AcademicTerm::query()->where('id', (int) $request->academic_term_id)->value('session_label');
            $parts[] = 'Session: '.($label ?: '#'.$request->academic_term_id);
        } elseif ($request->filled('session')) {
            $parts[] = 'Session: '.$request->string('session');
        }
        if ($request->filled('stage')) {
            $parts[] = 'Stage: '.str_replace('_', ' ', (string) $request->stage);
        }
        if ($request->filled('fee_status')) {
            $parts[] = 'Fee: '.$request->string('fee_status');
        }

        return $parts;
    }

    /**
     * @return array{by_stage: array<string, int>, total: int}
     */
    public static function stageSummary(Request $request, ?User $user = null): array
    {
        $counts = static::fromRequest($request, $user, ['stage'])
            ->setEagerLoads([])
            ->reorder()
            ->select('stage')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('stage')
            ->pluck('aggregate', 'stage')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'by_stage' => $counts,
            'total' => (int) array_sum($counts),
        ];
    }
}
