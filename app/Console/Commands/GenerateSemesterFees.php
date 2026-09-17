<?php

namespace App\Console\Commands;

use App\Services\SemesterFeeService;
use Illuminate\Console\Command;
use RuntimeException;

class GenerateSemesterFees extends Command
{
    protected $signature = 'fees:generate-semester {--term= : Academic term id (defaults to current term)}';

    protected $description = 'Generate semester fee invoices for all active enrolled students for a term';

    public function handle(SemesterFeeService $semesterFees): int
    {
        $termOption = $this->option('term');
        $termId = $termOption !== null && $termOption !== '' ? (int) $termOption : null;

        try {
            $summary = $semesterFees->generateForTerm($termId);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Semester fee ₦%s · term #%d · created %d · skipped %d · failed %d',
            number_format($summary['amount'], 2),
            $summary['academic_term_id'],
            $summary['created'],
            $summary['skipped'],
            $summary['failed'],
        ));

        if ($summary['failures'] !== []) {
            foreach (array_slice($summary['failures'], 0, 20) as $failure) {
                $this->warn(sprintf('Student #%d: %s', $failure['student_id'], $failure['message']));
            }
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
