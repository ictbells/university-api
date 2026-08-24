<?php

namespace App\Support;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class RegistrationListQuery
{
    public static function fromRequest(Request $request, array $except = []): Builder
    {
        $query = RegistrationCriteria::studentsQuery()
            ->with([
                'user',
                'program.department',
                'application.intake.term',
                'invoices' => fn ($q) => $q->where('category', 'tuition')->whereIn('status', ['paid', 'partial'])->latest(),
            ]);

        if ($request->filled('entry_mode') && ! in_array('entry_mode', $except, true)) {
            $query->whereHas('application', fn ($q) => $q->where('entry_mode', $request->entry_mode));
        }
        if ($request->filled('entry_modes')) {
            $modes = is_array($request->entry_modes)
                ? $request->entry_modes
                : array_filter(array_map('trim', explode(',', (string) $request->entry_modes)));
            if ($modes !== []) {
                $query->whereHas('application', fn ($q) => $q->whereIn('entry_mode', $modes));
            }
        }
        if ($request->filled('academic_session_id')) {
            $sessionId = (int) $request->academic_session_id;
            $query->whereHas('application.intake.term', fn ($term) => $term->where('academic_session_id', $sessionId));
        } elseif ($request->filled('academic_term_id')) {
            $termId = (int) $request->academic_term_id;
            $query->whereHas('application.intake', fn ($intake) => $intake->where('academic_term_id', $termId));
        } elseif ($request->filled('session')) {
            $session = (string) $request->session;
            $query->whereHas('application.intake.term', fn ($term) => $term->where('session_label', $session));
        }
        if ($request->filled('program_id')) {
            $query->where('program_id', (int) $request->program_id);
        }
        if ($request->filled('course_reg_status') && ! in_array('course_reg_status', $except, true)) {
            $term = AcademicTerm::current();
            $status = (string) $request->course_reg_status;
            if ($term) {
                $enrolledThisTerm = fn ($q) => $q->enrolled()->whereHas('offering', fn ($o) => $o->where('academic_term_id', $term->id));
                if ($status === 'not_started') {
                    $query->whereDoesntHave('enrollments', $enrolledThisTerm);
                } elseif (in_array($status, ['in_progress', 'registered'], true)) {
                    $query->whereHas('enrollments', $enrolledThisTerm);
                }
            }
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $like = '%'.$search.'%';
            $query->where(function (Builder $builder) use ($like, $search) {
                $builder->where('first_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('matric_number', 'like', $like)
                    ->orWhere('student_number', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhereHas('user', function ($userQuery) use ($like) {
                        $userQuery->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    })
                    ->orWhereHas('program', function ($program) use ($like) {
                        $program->where('name', 'like', $like)
                            ->orWhere('code', 'like', $like);
                    })
                    ->orWhereHas('application', function ($application) use ($like) {
                        $application->where('application_number', 'like', $like)
                            ->orWhere('jamb_registration', 'like', $like);
                    });

                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search);
                }
            });
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
        } elseif ($request->filled('entry_modes')) {
            $modes = is_array($request->entry_modes)
                ? $request->entry_modes
                : array_filter(array_map('trim', explode(',', (string) $request->entry_modes)));
            if ($modes !== []) {
                $parts[] = 'Entry modes: '.strtoupper(implode(', ', $modes));
            }
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
        if ($request->filled('program_id')) {
            $program = Program::query()->where('id', (int) $request->program_id)->first(['name', 'code']);
            $parts[] = 'Programme: '.($program?->name ?: ($program?->code ?: '#'.$request->program_id));
        }
        if ($request->filled('course_reg_status')) {
            $parts[] = 'Course registration: '.str_replace('_', ' ', (string) $request->course_reg_status);
        }

        return $parts;
    }

    /**
     * @return array{by_entry_mode: array<string, int>, programmes: int, total: int}
     */
    public static function summary(Request $request): array
    {
        $query = static::fromRequest($request, ['entry_mode'])
            ->clone()
            ->reorder()
            ->setEagerLoads([]);

        $byMode = (clone $query)
            ->join('applications', 'applications.id', '=', 'students.application_id')
            ->selectRaw('applications.entry_mode as entry_mode, COUNT(DISTINCT students.id) as aggregate')
            ->groupBy('applications.entry_mode')
            ->pluck('aggregate', 'entry_mode')
            ->map(fn ($n) => (int) $n)
            ->all();

        $programmes = (int) (clone $query)
            ->selectRaw('COUNT(DISTINCT program_id) as aggregate')
            ->value('aggregate');

        return [
            'by_entry_mode' => $byMode,
            'programmes' => $programmes,
            'total' => (int) array_sum($byMode),
        ];
    }
}
