<?php

namespace App\Console\Commands;

use App\Models\AcademicSession;
use App\Services\AcademicCalendarService;
use App\Services\AuditWriter;
use App\Services\SessionCloseService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncAcademicCalendar extends Command
{
    protected $signature = 'academic:sync-calendar {--date= : Evaluate as this date (Y-m-d) instead of today}';

    protected $description = 'Auto-close semesters and sessions based on configured dates; promote students when sessions close';

    public function handle(AcademicCalendarService $calendar, SessionCloseService $sessionClose, AuditWriter $audit): int
    {
        $today = now()->startOfDay();
        if ($date = $this->option('date')) {
            try {
                $today = Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay();
            } catch (\Throwable) {
                throw new InvalidArgumentException('Invalid --date value. Use Y-m-d format.');
            }
        }

        $result = $calendar->sync($today);

        foreach ($result['closed'] as $term) {
            $label = $this->semesterLabel($term);
            $this->line("Closed: {$label} (ended {$term->ends_on?->toDateString()})");
            $audit->record(
                'academic_calendar.semester_closed',
                "Semester auto-closed: {$label}",
                'academic',
                'academic_term',
                $term->id,
                ['is_current' => true],
                ['is_current' => false],
                'Scheduled calendar sync',
            );
        }

        if ($result['opened']) {
            $term = $result['opened'];
            $label = $this->semesterLabel($term);
            $this->info("Opened: {$label} (started {$term->starts_on?->toDateString()})");
            $audit->record(
                'academic_calendar.semester_opened',
                "Semester auto-opened: {$label}",
                'academic',
                'academic_term',
                $term->id,
                null,
                ['is_current' => true, 'session' => $term->session_label, 'name' => $term->name],
                'Scheduled calendar sync',
            );
        }

        if ($result['closed'] === [] && ! $result['opened']) {
            $this->comment('No semester calendar changes for '.$today->toDateString().'.');
        }

        $sessionsClosed = 0;
        $dueSessions = AcademicSession::query()
            ->where('auto_close_on_end', true)
            ->whereNull('closed_at')
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<', $today)
            ->orderBy('id')
            ->get();

        foreach ($dueSessions as $session) {
            try {
                $resultClose = $sessionClose->close($session, 'auto', null);
                $sessionsClosed++;
                $this->info(sprintf(
                    'Closed session %s — promoted %d students.',
                    $session->label,
                    $resultClose['promoted_count'],
                ));
                $audit->record(
                    'session.auto_closed',
                    'Academic session auto-closed with promotion',
                    'academic',
                    'academic_session',
                    $session->id,
                    null,
                    $resultClose['closure']->toArray(),
                    'Scheduled calendar sync',
                );
            } catch (\Throwable $e) {
                $this->error("Failed to auto-close session {$session->label}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function semesterLabel($term): string
    {
        return trim(($term->session_label ?: $term->session?->label ?: '').' '.$term->name);
    }
}
