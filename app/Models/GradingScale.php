<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingScale extends Model
{
    public const DEFAULT_NAME = 'Default 5.0 Scale';

    /** @var list<array{letter: string, min_score: float, max_score: float, grade_point: float}> */
    public const DEFAULT_BOUNDARIES = [
        ['letter' => 'A', 'min_score' => 70, 'max_score' => 100, 'grade_point' => 5.0],
        ['letter' => 'B', 'min_score' => 60, 'max_score' => 69.99, 'grade_point' => 4.0],
        ['letter' => 'C', 'min_score' => 50, 'max_score' => 59.99, 'grade_point' => 3.0],
        ['letter' => 'D', 'min_score' => 45, 'max_score' => 49.99, 'grade_point' => 2.0],
        ['letter' => 'E', 'min_score' => 40, 'max_score' => 44.99, 'grade_point' => 1.0],
        ['letter' => 'F', 'min_score' => 0, 'max_score' => 39.99, 'grade_point' => 0.0],
    ];

    protected $fillable = ['name', 'max_points', 'is_default'];

    protected function casts(): array
    {
        return [
            'max_points' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    public function boundaries(): HasMany
    {
        return $this->hasMany(GradeBoundary::class)->orderByDesc('min_score');
    }

    public static function ensureDefault(): self
    {
        $scale = static::query()->where('is_default', true)->first()
            ?? static::query()->where('name', self::DEFAULT_NAME)->first()
            ?? static::query()->orderBy('id')->first();

        if (! $scale) {
            $scale = static::query()->create([
                'name' => self::DEFAULT_NAME,
                'max_points' => 5.0,
                'is_default' => true,
            ]);
        }

        if (! $scale->is_default) {
            static::query()->where('id', '!=', $scale->id)->update(['is_default' => false]);
            $scale->forceFill(['is_default' => true])->save();
        }

        if ($scale->boundaries()->doesntExist()) {
            foreach (self::DEFAULT_BOUNDARIES as $row) {
                $scale->boundaries()->create($row);
            }
        }

        return $scale->load('boundaries');
    }
}
