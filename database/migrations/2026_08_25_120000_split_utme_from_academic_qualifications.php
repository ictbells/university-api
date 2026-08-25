<?php

use App\Models\Application;
use App\Models\ApplicationStep;
use App\Support\ApplicationFormSteps;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Application::query()
            ->where('entry_mode', 'utme')
            ->orderBy('id')
            ->chunkById(100, function ($apps) {
                foreach ($apps as $app) {
                    $app->ensureFormSteps();
                }
            });

        // Extra pass: copy legacy UTME blobs even when utme step already existed empty
        ApplicationStep::query()
            ->where('step_key', 'academic_qualifications')
            ->whereNotNull('payload')
            ->orderBy('id')
            ->chunkById(100, function ($steps) {
                foreach ($steps as $academic) {
                    $payload = is_array($academic->payload) ? $academic->payload : [];
                    $legacy = is_array($payload['utme'] ?? null) ? $payload['utme'] : null;
                    if (! $legacy || ApplicationFormSteps::utmeIsEmpty($legacy)) {
                        continue;
                    }

                    $app = Application::query()->find($academic->application_id);
                    if (! $app || $app->entry_mode !== 'utme') {
                        continue;
                    }

                    $utmeStep = ApplicationStep::query()
                        ->where('application_id', $app->id)
                        ->where('step_key', 'utme')
                        ->first();
                    if (! $utmeStep) {
                        $utmeStep = ApplicationStep::query()->create([
                            'application_id' => $app->id,
                            'step_key' => 'utme',
                            'status' => 'saved',
                            'payload' => ['utme' => $legacy],
                        ]);
                    } else {
                        $existing = is_array($utmeStep->payload['utme'] ?? null) ? $utmeStep->payload['utme'] : null;
                        if (ApplicationFormSteps::utmeIsEmpty($existing)) {
                            $utmeStep->update([
                                'payload' => ['utme' => $legacy],
                                'status' => $utmeStep->status === 'pending' ? 'saved' : $utmeStep->status,
                            ]);
                        }
                    }

                    unset($payload['utme']);
                    $academic->update(['payload' => $payload]);
                }
            });
    }

    public function down(): void
    {
        // Keep utme steps; merging back is unnecessary for rollback of structure.
    }
};
