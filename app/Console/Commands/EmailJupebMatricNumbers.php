<?php

namespace App\Console\Commands;

use App\Services\JupebMatricAssignService;
use Illuminate\Console\Command;

class EmailJupebMatricNumbers extends Command
{
    protected $signature = 'jupeb:email-matric
                            {--dry-run : List who would be emailed without sending}
                            {--id=* : Limit to these student IDs}';

    protected $description = 'Email already assigned JUPEB matric numbers so those students can sign in to the student portal.';

    public function handle(JupebMatricAssignService $matric): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $result = $matric->emailAssigned($ids, $dryRun);

        if ($result['sent'] === 0 && $result['skipped'] === 0) {
            $this->warn($ids !== []
                ? 'No matching JUPEB students with a matric number were found.'
                : 'No JUPEB students with a matric number were found.');

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? ($result['sent'] === 1
                ? 'Would email 1 JUPEB student.'
                : "Would email {$result['sent']} JUPEB students.")
            : ($result['sent'] === 1
                ? 'Sent 1 JUPEB matric email.'
                : "Sent {$result['sent']} JUPEB matric emails."));

        if ($result['skipped'] > 0) {
            $this->warn($result['skipped'] === 1
                ? 'Skipped 1 student with no account email.'
                : "Skipped {$result['skipped']} students with no account email.");
        }

        return self::SUCCESS;
    }
}
