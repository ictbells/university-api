<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StateOfOriginSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/nigeria_states_lgas.json');
        if (! is_file($path)) {
            $this->command?->error("Missing {$path}. Export with: php scripts/export-states-lgas.php");

            return;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            $this->command?->error('Invalid nigeria_states_lgas.json');

            return;
        }

        $states = $payload['states'] ?? [];
        $lgas = $payload['lgas'] ?? [];

        DB::transaction(function () use ($states, $lgas) {
            foreach (array_chunk($states, 100) as $chunk) {
                foreach ($chunk as $row) {
                    DB::table('stateoforigin')->updateOrInsert(
                        ['state_id' => (int) $row['state_id']],
                        ['state_title' => (string) $row['state_title']],
                    );
                }
            }

            foreach (array_chunk($lgas, 100) as $chunk) {
                foreach ($chunk as $row) {
                    DB::table('lga')->updateOrInsert(
                        ['lga_id' => (int) $row['lga_id']],
                        [
                            'lga_title' => (string) $row['lga_title'],
                            'state_id' => (int) $row['state_id'],
                        ],
                    );
                }
            }
        });

        $this->command?->info(sprintf(
            'Seeded %d states and %d LGAs.',
            count($states),
            count($lgas),
        ));
    }
}
