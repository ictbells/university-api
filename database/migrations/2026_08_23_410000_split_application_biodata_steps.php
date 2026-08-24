<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NEW_STEPS = [
        'personal_details',
        'health_information',
        'next_of_kin',
        'sponsor',
    ];

    private const FIELD_MAP = [
        'personal_details' => ['marital_status', 'religion', 'country', 'state', 'lga'],
        'health_information' => ['blood_group', 'genotype', 'has_medical_condition', 'medical_condition_details'],
        'next_of_kin' => [
            'next_of_kin',
            'next_of_kin_relationship',
            'next_of_kin_phone',
            'next_of_kin_email',
            'next_of_kin_address',
        ],
        'sponsor' => [
            'sponsor_name',
            'sponsor_relationship',
            'sponsor_phone',
            'sponsor_email',
            'sponsor_address',
        ],
    ];

    public function up(): void
    {
        $applications = DB::table('applications')->select('id')->get();

        foreach ($applications as $application) {
            $biodata = DB::table('application_steps')
                ->where('application_id', $application->id)
                ->where('step_key', 'biodata')
                ->first();

            $payload = [];
            if ($biodata && is_string($biodata->payload)) {
                $decoded = json_decode($biodata->payload, true);
                $payload = is_array($decoded) ? $decoded : [];
            } elseif ($biodata && is_array($biodata->payload)) {
                $payload = $biodata->payload;
            }

            foreach (self::NEW_STEPS as $stepKey) {
                $exists = DB::table('application_steps')
                    ->where('application_id', $application->id)
                    ->where('step_key', $stepKey)
                    ->exists();

                $stepPayload = [];
                foreach (self::FIELD_MAP[$stepKey] as $field) {
                    if (array_key_exists($field, $payload)) {
                        $stepPayload[$field] = $payload[$field];
                    }
                }

                $hasData = collect($stepPayload)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
                $status = $hasData ? 'saved' : 'pending';

                if (! $exists) {
                    DB::table('application_steps')->insert([
                        'application_id' => $application->id,
                        'step_key' => $stepKey,
                        'status' => $status,
                        'payload' => json_encode($stepPayload),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } elseif ($hasData) {
                    DB::table('application_steps')
                        ->where('application_id', $application->id)
                        ->where('step_key', $stepKey)
                        ->update([
                            'status' => $status,
                            'payload' => json_encode($stepPayload),
                            'updated_at' => now(),
                        ]);
                }
            }

            // Strip migrated fields from biodata so identity step stays clean.
            if ($biodata && $payload) {
                $identityKeys = ['nin', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'photo_path', 'nin_locked'];
                $clean = array_intersect_key($payload, array_flip($identityKeys));
                DB::table('application_steps')
                    ->where('id', $biodata->id)
                    ->update([
                        'payload' => json_encode($clean),
                        'updated_at' => now(),
                    ]);
            }
        }

        // Ensure FORM_STEPS order is reflected for newly created apps via model constant;
        // existing apps already have all keys after this migration.
        unset($applications);
    }

    public function down(): void
    {
        DB::table('application_steps')
            ->whereIn('step_key', self::NEW_STEPS)
            ->delete();
    }
};
