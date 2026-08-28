<?php

namespace Database\Seeders;

use App\Models\GradingScale;
use Illuminate\Database\Seeder;

class GradingScaleSeeder extends Seeder
{
    public function run(): void
    {
        GradingScale::ensureDefault();
    }
}
