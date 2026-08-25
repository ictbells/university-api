<?php

/**
 * Re-export stateoforigin / lga from the current app database into
 * database/data/nigeria_states_lgas.json for StateOfOriginSeeder.
 *
 * Usage: php scripts/export-states-lgas.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$states = DB::table('stateoforigin')->orderBy('state_id')->get(['state_id', 'state_title']);
$lgas = DB::table('lga')->orderBy('lga_id')->get(['lga_id', 'lga_title', 'state_id']);

if ($states->isEmpty() || $lgas->isEmpty()) {
    fwrite(STDERR, "No state/LGA rows found in the current database.\n");
    exit(1);
}

$out = [
    'states' => $states->map(fn ($r) => [
        'state_id' => (int) $r->state_id,
        'state_title' => (string) $r->state_title,
    ])->values()->all(),
    'lgas' => $lgas->map(fn ($r) => [
        'lga_id' => (int) $r->lga_id,
        'lga_title' => (string) $r->lga_title,
        'state_id' => (int) $r->state_id,
    ])->values()->all(),
];

$dir = __DIR__.'/../database/data';
if (! is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$path = $dir.'/nigeria_states_lgas.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");

echo 'states='.count($out['states']).PHP_EOL;
echo 'lgas='.count($out['lgas']).PHP_EOL;
echo "wrote {$path}\n";
