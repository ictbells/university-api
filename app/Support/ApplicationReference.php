<?php

namespace App\Support;

use App\Models\Application;

class ApplicationReference
{
    public static function generate(): string
    {
        $year = now()->format('Y');
        $prefix = "APP/{$year}/";

        $last = Application::withTrashed()
            ->where('application_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('application_number');

        $sequence = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
