<?php

namespace App\Console\Commands;

use App\Services\StudentCreationService;
use Illuminate\Console\Command;

class EmailStudentMatricNumbers extends Command
{
    protected $signature = 'students:email-matric
                            {--dry-run : List who would be emailed without sending}
                            {--id=* : Limit to these student IDs}
                            {--entry-mode= : Limit to an admission category (utme, de, transfer, pg)}';

    protected $description = 'Email already issued undergraduate, DE, transfer, and postgraduate matric numbers so those students can sign in to the student portal.';

    public function handle(StudentCreationService $students): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $entryMode = strtolower(trim((string) $this->option('entry-mode')));
        $result = $students->emailAssigned($ids, $dryRun, $entryMode !== '' ? $entryMode : null);

        if ($result['sent'] === 0 && $result['skipped'] === 0) {
            $this->warn($ids !== []
                ? 'No matching students with a matric number were found.'
                : 'No students with a matric number were found.');

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? ($result['sent'] === 1
                ? 'Would email 1 student.'
                : "Would email {$result['sent']} students.")
            : ($result['sent'] === 1
                ? 'Sent 1 matric email.'
                : "Sent {$result['sent']} matric emails."));

        if ($result['skipped'] > 0) {
            $this->warn($result['skipped'] === 1
                ? 'Skipped 1 student with no account email.'
                : "Skipped {$result['skipped']} students with no account email.");
        }

        return self::SUCCESS;
    }
}
