<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Invoice;
use App\Support\AdmissionEntryRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationDeletionService
{
    public function __construct(
        private AuditWriter $audit,
        private InvoiceService $invoices,
    ) {}

    public function assertCanDelete(Application $application): void
    {
        $reason = $application->deletionBlockReason();
        if ($reason) {
            throw ValidationException::withMessages(['application' => [$reason]]);
        }
    }

    /**
     * Soft-delete one application file without touching the shared applicant account.
     *
     * @return array{message: string, remaining_application_id: int|null}
     */
    public function delete(Application $application, string $reason): array
    {
        $this->assertCanDelete($application);
        $application->loadMissing(['steps', 'documents', 'reviews', 'user']);

        $before = [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'entry_mode' => $application->entry_mode,
            'stage' => $application->stage,
            'user_id' => $application->user_id,
        ];
        $userId = $application->user_id;
        $label = trim(($application->application_number ?: '#'.$application->id).' '.AdmissionEntryRules::entryModeLabel($application->entry_mode));

        DB::transaction(function () use ($application, $reason) {
            $this->cancelUnpaidInvoices($application, $reason);

            $application->steps()->delete();
            $application->documents()->delete();
            $application->reviews()->delete();
            $application->delete();
        });

        $remainingId = $userId
            ? Application::query()->where('user_id', $userId)->latest('id')->value('id')
            : null;

        $this->audit->record(
            'application.deleted',
            'Deleted application file '.$label,
            'admissions',
            'application',
            $application->id,
            $before,
            ['remaining_application_id' => $remainingId],
            $reason,
        );

        return [
            'message' => 'Application file deleted. The applicant account was kept.',
            'remaining_application_id' => $remainingId ? (int) $remainingId : null,
        ];
    }

    private function cancelUnpaidInvoices(Application $application, string $reason): void
    {
        $ids = array_values(array_filter([
            $application->application_fee_invoice_id,
            $application->acceptance_fee_invoice_id,
        ]));

        $invoices = Invoice::query()
            ->where(function ($query) use ($application, $ids) {
                $query->where('application_id', $application->id);
                if ($ids !== []) {
                    $query->orWhereIn('id', $ids);
                }
            })
            ->get();

        foreach ($invoices as $invoice) {
            if ($invoice->status !== 'unpaid') {
                continue;
            }
            $this->invoices->disable($invoice, $reason);
        }
    }
}
