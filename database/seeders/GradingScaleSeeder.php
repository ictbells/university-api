<?php

namespace Database\Seeders;

use App\Models\GradeBoundary;
use App\Models\GradingScale;
use Illuminate\Database\Seeder;

class GradingScaleSeeder extends Seeder
{
    public function run(): void
    {
        $scale = GradingScale::query()->updateOrCreate(
            ['name' => 'Default 5.0 Scale'],
            ['max_points' => 5.0, 'is_default' => true],
        );

        GradingScale::query()
            ->where('id', '!=', $scale->id)
            ->update(['is_default' => false]);

        $boundaries = [
            ['letter' => 'A', 'min_score' => 70, 'max_score' => 100, 'grade_point' => 5.0],
            ['letter' => 'B', 'min_score' => 60, 'max_score' => 69.99, 'grade_point' => 4.0],
            ['letter' => 'C', 'min_score' => 50, 'max_score' => 59.99, 'grade_point' => 3.0],
            ['letter' => 'D', 'min_score' => 45, 'max_score' => 49.99, 'grade_point' => 2.0],
            ['letter' => 'E', 'min_score' => 40, 'max_score' => 44.99, 'grade_point' => 1.0],
            ['letter' => 'F', 'min_score' => 0, 'max_score' => 39.99, 'grade_point' => 0.0],
        ];

        foreach ($boundaries as $row) {
            GradeBoundary::query()->updateOrCreate(
                ['grading_scale_id' => $scale->id, 'letter' => $row['letter']],
                $row,
            );
        }
    }
}
