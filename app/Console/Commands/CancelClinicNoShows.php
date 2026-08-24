<?php

namespace App\Console\Commands;

use App\Services\ClinicAppointmentService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CancelClinicNoShows extends Command
{
    protected $signature = 'clinic:cancel-no-shows {--date= : Treat this date (Y-m-d) as today}';

    protected $description = 'Cancel pending and scheduled clinic appointments whose visit day has passed without arrival';

    public function handle(ClinicAppointmentService $appointments): int
    {
        $today = now('Africa/Lagos')->startOfDay();
        if ($date = $this->option('date')) {
            try {
                $today = Carbon::createFromFormat('Y-m-d', (string) $date, 'Africa/Lagos')->startOfDay();
            } catch (\Throwable) {
                throw new InvalidArgumentException('Invalid --date value. Use Y-m-d format.');
            }
        }

        $count = $appointments->cancelNoShows($today);
        $this->info("Cancelled {$count} clinic appointment(s) that were not attended.");

        return self::SUCCESS;
    }
}
