<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AcademicCalendarService
{
    /**
     * Close expired semesters and activate the semester whose date window includes today.
     *
     * @return array{closed: list<AcademicTerm>, opened: AcademicTerm|null}
     */
    public function sync(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $closed = [];

        $expired = AcademicTerm::query()
            ->with('session')
            ->where('is_current', true)
            ->where('auto_schedule', true)
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<', $today)
            ->get();

        foreach ($expired as $term) {
            $term->update(['is_current' => false]);
            $closed[] = $term->fresh('session');
        }

        $candidate = $this->resolveActiveSemester($today);

        $opened = null;
        if ($candidate && ! $candidate->is_current) {
            $opened = $this->activateSemester($candidate);
        } elseif ($candidate?->is_current) {
            Setting::setValue('current_term_id', $candidate->id);
        }

        return ['closed' => $closed, 'opened' => $opened];
    }

    public function activateSemester(AcademicTerm $term): AcademicTerm
    {
        return DB::transaction(function () use ($term) {
            AcademicTerm::query()->update(['is_current' => false]);
            $term->update(['is_current' => true]);
            Setting::setValue('current_term_id', $term->id);

            return $term->fresh('session');
        });
    }

    private function resolveActiveSemester(Carbon $today): ?AcademicTerm
    {
        return AcademicTerm::query()
            ->with('session')
            ->where('auto_schedule', true)
            ->whereNotNull('starts_on')
            ->whereDate('starts_on', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('ends_on')
                    ->orWhereDate('ends_on', '>=', $today);
            })
            ->whereHas('session', function ($query) use ($today) {
                $query->where(function ($session) use ($today) {
                    $session->whereNull('starts_on')
                        ->orWhereDate('starts_on', '<=', $today);
                })->where(function ($session) use ($today) {
                    $session->whereNull('ends_on')
                        ->orWhereDate('ends_on', '>=', $today);
                });
            })
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }
}
