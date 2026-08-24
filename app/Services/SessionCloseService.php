<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\AcademicSessionClosure;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\StudentLevelProgression;
use App\Models\User;
use App\Support\LevelProgression;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionCloseService
{
    private const CHUNK_SIZE = 500;

    private const SAMPLE_LIMIT = 5;

    public function __construct(private AuditWriter $audit) {}

    /**
     * @return array{
     *   session_id: int,
     *   session_label: string,
     *   is_closed: bool,
     *   promoted_count: int,
     *   skipped_final_count: int,
     *   skipped_inactive_count: int,
     *   skipped_no_program_count: int,
     *   samples: array<string, list<array<string, mixed>>>
     * }
     */
    public function preview(AcademicSession $session): array
    {
        $this->assertNotClosed($session);

        return $this->summarize($session, write: false);
    }

    /**
     * @return array{
     *   session: AcademicSession,
     *   closure: AcademicSessionClosure,
     *   promoted_count: int,
     *   skipped_final_count: int,
     *   skipped_inactive_count: int,
     *   skipped_no_program_count: int
     * }
     */
    public function close(AcademicSession $session, string $trigger, ?User $actor = null): array
    {
        if (! in_array($trigger, ['manual', 'auto'], true)) {
            throw ValidationException::withMessages(['trigger' => ['Invalid session close trigger.']]);
        }

        return DB::transaction(function () use ($session, $trigger, $actor) {
            $locked = AcademicSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->assertNotClosed($locked);

            $summary = $this->summarize($locked, write: true, actor: $actor);

            $locked->update([
                'closed_at' => now(),
                'closed_by_user_id' => $actor?->id,
            ]);

            AcademicTerm::query()
                ->where('academic_session_id', $locked->id)
                ->update(['is_current' => false]);

            $closure = AcademicSessionClosure::query()->create([
                'academic_session_id' => $locked->id,
                'trigger' => $trigger,
                'promoted_count' => $summary['promoted_count'],
                'skipped_final_count' => $summary['skipped_final_count'],
                'skipped_inactive_count' => $summary['skipped_inactive_count'],
                'skipped_no_program_count' => $summary['skipped_no_program_count'],
                'ran_at' => now(),
                'ran_by_user_id' => $actor?->id,
            ]);

            $this->audit->record(
                'session.closed',
                'Academic session closed with student level promotion',
                'academic',
                'academic_session',
                $locked->id,
                null,
                [
                    'trigger' => $trigger,
                    'promoted_count' => $summary['promoted_count'],
                    'skipped_final_count' => $summary['skipped_final_count'],
                    'skipped_inactive_count' => $summary['skipped_inactive_count'],
                    'skipped_no_program_count' => $summary['skipped_no_program_count'],
                ],
                $locked->label,
            );

            return [
                'session' => $locked->fresh(['semesters', 'latestClosure']),
                'closure' => $closure,
                'promoted_count' => $summary['promoted_count'],
                'skipped_final_count' => $summary['skipped_final_count'],
                'skipped_inactive_count' => $summary['skipped_inactive_count'],
                'skipped_no_program_count' => $summary['skipped_no_program_count'],
            ];
        });
    }

    private function assertNotClosed(AcademicSession $session): void
    {
        if ($session->closed_at !== null) {
            throw ValidationException::withMessages([
                'session' => ['This academic session is already closed.'],
            ]);
        }
    }

    /**
     * @return array{
     *   session_id: int,
     *   session_label: string,
     *   is_closed: bool,
     *   promoted_count: int,
     *   skipped_final_count: int,
     *   skipped_inactive_count: int,
     *   skipped_no_program_count: int,
     *   samples: array<string, list<array<string, mixed>>>
     * }
     */
    private function summarize(AcademicSession $session, bool $write, ?User $actor = null): array
    {
        $counts = [
            'promoted_count' => 0,
            'skipped_final_count' => 0,
            'skipped_inactive_count' => 0,
            'skipped_no_program_count' => 0,
        ];
        $samples = [
            'promoted' => [],
            'skipped_final' => [],
            'skipped_inactive' => [],
            'skipped_no_program' => [],
        ];
        $progressionRows = [];
        $now = now();

        Student::query()
            ->with('program')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($students) use (
                $session,
                $write,
                &$counts,
                &$samples,
                &$progressionRows,
                $now,
            ) {
                foreach ($students as $student) {
                    $bucket = $this->classify($student);
                    $counts[$bucket.'_count']++;

                    if (count($samples[$this->sampleKey($bucket)]) < self::SAMPLE_LIMIT) {
                        $samples[$this->sampleKey($bucket)][] = $this->sampleRow($student, $bucket);
                    }

                    if ($bucket !== 'promoted') {
                        continue;
                    }

                    $program = $student->program;
                    if (! $program) {
                        continue;
                    }

                    $fromLevel = (int) $student->current_level;
                    $toLevel = LevelProgression::nextLevel($fromLevel, $program);
                    if ($toLevel === null) {
                        continue;
                    }

                    if ($write) {
                        $student->update(['current_level' => $toLevel]);
                        $progressionRows[] = [
                            'student_id' => $student->id,
                            'academic_session_id' => $session->id,
                            'program_id' => $program->id,
                            'from_level' => $fromLevel,
                            'to_level' => $toLevel,
                            'created_at' => $now,
                        ];
                    }
                }

                if ($write && $progressionRows !== []) {
                    StudentLevelProgression::query()->insert($progressionRows);
                    $progressionRows = [];
                }
            });

        if ($write && $progressionRows !== []) {
            StudentLevelProgression::query()->insert($progressionRows);
        }

        return [
            'session_id' => $session->id,
            'session_label' => $session->label,
            'is_closed' => false,
            'promoted_count' => $counts['promoted_count'],
            'skipped_final_count' => $counts['skipped_final_count'],
            'skipped_inactive_count' => $counts['skipped_inactive_count'],
            'skipped_no_program_count' => $counts['skipped_no_program_count'],
            'samples' => $samples,
        ];
    }

    private function classify(Student $student): string
    {
        if ($student->status !== 'active') {
            return 'skipped_inactive';
        }

        if (! $student->program_id || ! $student->program) {
            return 'skipped_no_program';
        }

        $next = LevelProgression::nextLevel((int) $student->current_level, $student->program);

        return $next === null ? 'skipped_final' : 'promoted';
    }

    private function sampleKey(string $bucket): string
    {
        return match ($bucket) {
            'promoted' => 'promoted',
            'skipped_final' => 'skipped_final',
            'skipped_inactive' => 'skipped_inactive',
            'skipped_no_program' => 'skipped_no_program',
            default => $bucket,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleRow(Student $student, string $bucket): array
    {
        $program = $student->program;
        $fromLevel = (int) $student->current_level;
        $toLevel = $program ? LevelProgression::nextLevel($fromLevel, $program) : null;

        return [
            'student_id' => $student->id,
            'matric_number' => $student->matric_number,
            'name' => trim($student->first_name.' '.$student->last_name),
            'program' => $program?->name,
            'from_level' => $fromLevel,
            'to_level' => $bucket === 'promoted' ? $toLevel : $fromLevel,
            'final_level' => $program ? LevelProgression::finalLevelForProgram($program) : null,
        ];
    }
}
