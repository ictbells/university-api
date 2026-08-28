<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class AcademicLevel extends BaseModel
{
    protected $fillable = ['name', 'code', 'study_level', 'sort_order', 'is_active'];

    /**
     * @return list<int>
     */
    public static function idsMatching(?string $level): array
    {
        $raw = trim((string) $level);
        if ($raw === '') {
            return [];
        }

        $digits = preg_match('/(\d{2,3})/', $raw, $match) ? $match[1] : null;

        return static::query()
            ->where(function (Builder $q) use ($raw, $digits) {
                $q->where('code', $raw)->orWhere('name', $raw);
                if (ctype_digit($raw)) {
                    $q->orWhere('id', (int) $raw);
                }
                if ($digits) {
                    $q->orWhere('code', $digits)
                        ->orWhere('code', $digits.'L')
                        ->orWhere('name', 'like', $digits.'%');
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
