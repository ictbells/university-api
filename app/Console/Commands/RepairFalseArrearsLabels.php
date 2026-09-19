<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use Illuminate\Console\Command;

class RepairFalseArrearsLabels extends Command
{
    protected $signature = 'invoices:repair-false-arrears-labels';

    protected $description = 'Strip wrongly stamped “(… · arrears)” text from current-level invoice particulars';

    public function handle(InvoiceService $invoices): int
    {
        $updated = $invoices->repairFalseArrearsLabels();
        $this->info("Updated {$updated} invoice item description(s).");

        return self::SUCCESS;
    }
}
