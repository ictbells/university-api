<?php

namespace App\Console\Commands;

use App\Services\GraduationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ExpireStudentship extends Command
{
    protected $signature = 'students:expire-studentship {--date= : Evaluate as this date (Y-m-d) instead of today}';

    protected $description = 'Mark graduated students as alumni when studentship expires (default: 2 years after conferment)';

    public function handle(GraduationService $graduation): int
    {
        $today = now()->startOfDay();
        if ($date = $this->option('date')) {
            try {
                $today = Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay();
            } catch (\Throwable) {
                throw new InvalidArgumentException('Invalid --date value. Use Y-m-d format.');
            }
        }

        $count = $graduation->expireDue($today);
        $this->info($count === 1
            ? 'Expired studentship for 1 student.'
            : "Expired studentship for {$count} students.");

        return self::SUCCESS;
    }
}
