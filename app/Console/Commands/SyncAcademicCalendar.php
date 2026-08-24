<?php

namespace App\Console\Commands;

use App\Services\AcademicCalendarService;
use App\Services\AuditWriter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncAcademicCalendar extends Command
{
    protected $signature = 'academic:sync-calendar {--date= : Evaluate as this date (Y-m-d) instead of today}';

    protected $description = 'Auto-close and auto-start semesters based on session and semester dates';

    public function handle(AcademicCalendarService $calendar, AuditWriter $audit): int
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
            $this->comment('No calendar changes for '.$today->toDateString().'.');
        }

        return self::SUCCESS;
    }

    private function semesterLabel($term): string
    {
        return trim(($term->session_label ?: $term->session?->label ?: '').' '.$term->name);
    }
}
