<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

class ReceiptDate
{
    /**
     * Staff browsers show payment timestamps in local time (Africa/Lagos).
     * Receipt HTML is rendered on the server (often UTC), so convert explicitly.
     */
    public static function format(null|DateTimeInterface|string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $dt = $value instanceof CarbonInterface
            ? $value->copy()
            : Carbon::parse($value);

        return $dt->timezone(config('app.display_timezone', 'Africa/Lagos'))
            ->format('d M Y, h:i A');
    }
}
