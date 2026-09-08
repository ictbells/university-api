<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\TuitionProgress;
use Illuminate\Support\Facades\DB;

/**
 * Shared serial for invoice numbers and payment receipt numbers:
 * BUT/{admission year}/{####} e.g. BUT/2026/0001
 *
 * Counter lives in settings (bursary_doc_last). Collisions bump via taken().
 */
class BursaryDocumentSequence
{
    public const SETTING_KEY = 'bursary_doc_last';

    public function allocate(?int $year = null): string
    {
        $year ??= $this->year();

        return DB::transaction(function () use ($year) {
            $this->lock();
            $serial = $this->nextSerial($year);
            $number = $this->format($year, $serial);
            while ($this->taken($number)) {
                $serial++;
                $number = $this->format($year, $serial);
            }
            $this->persist($number);

            return $number;
        });
    }

    public function noteIssued(string $number): void
    {
        $parsed = $this->parse($number);
        if (! $parsed) {
            return;
        }

        DB::transaction(function () use ($parsed) {
            $this->lock();
            $current = $this->parse($this->highestKnown($parsed['year']));
            if ($current
                && (int) $current['year'] === $parsed['year']
                && (int) $current['serial'] >= $parsed['serial']
            ) {
                return;
            }
            $this->persist($this->format($parsed['year'], $parsed['serial']));
        });
    }

    public function year(): int
    {
        foreach ([
            (int) config('sis.bursary_doc_year'),
            (int) config('sis.matric_year'),
        ] as $override) {
            if ($override >= 2000 && $override <= 2100) {
                return $override;
            }
        }

        $sessionId = TuitionProgress::currentSessionId();
        if ($sessionId) {
            $label = (string) AcademicSession::query()->whereKey($sessionId)->value('label');
            if (preg_match('/(20\d{2})/', $label, $match)) {
                return (int) $match[1];
            }
        }

        return (int) now()->format('Y');
    }

    /**
     * @return array{year: int, serial: int}|null
     */
    public function parse(string $value): ?array
    {
        $value = strtoupper(trim(str_replace(' ', '', $value)));
        $prefix = preg_quote($this->prefix(), '/');
        if (! preg_match('/^'.$prefix.'\/(20\d{2})\/(\d+)$/', $value, $match)) {
            return null;
        }

        return [
            'year' => (int) $match[1],
            'serial' => (int) $match[2],
        ];
    }

    public function format(int $year, int $serial): string
    {
        $digits = max(1, (int) config('sis.bursary_doc_digits', 4));

        return $this->prefix().'/'.$year.'/'.str_pad((string) $serial, $digits, '0', STR_PAD_LEFT);
    }

    public function prefix(): string
    {
        $prefix = strtoupper(trim((string) config('sis.bursary_doc_prefix', 'BUT')));

        return $prefix !== '' ? $prefix : 'BUT';
    }

    private function lock(): void
    {
        Setting::query()->firstOrCreate(['key' => self::SETTING_KEY], ['value' => '']);
        Setting::query()->where('key', self::SETTING_KEY)->lockForUpdate()->first();
    }

    private function nextSerial(int $year): int
    {
        return $this->highestSerial($year) + 1;
    }

    private function highestSerial(int $year): int
    {
        $parsed = $this->parse($this->highestKnown($year));

        return $parsed && $parsed['year'] === $year ? $parsed['serial'] : 0;
    }

    private function highestKnown(int $year): string
    {
        $best = 0;
        $bestValue = '';
        foreach ($this->knownValues() as $value) {
            $parsed = $this->parse($value);
            if (! $parsed || $parsed['year'] !== $year) {
                continue;
            }
            if ($parsed['serial'] >= $best) {
                $best = $parsed['serial'];
                $bestValue = $this->format($parsed['year'], $parsed['serial']);
            }
        }

        return $bestValue;
    }

    /**
     * @return list<string>
     */
    private function knownValues(): array
    {
        return array_values(array_filter([
            (string) Setting::query()->where('key', self::SETTING_KEY)->value('value'),
            (string) config('sis.bursary_doc_last'),
        ]));
    }

    private function taken(string $number): bool
    {
        return Invoice::withTrashed()->where('number', $number)->exists()
            || Payment::query()->where('receipt_no', $number)->exists();
    }

    private function persist(string $number): void
    {
        Setting::setValue(self::SETTING_KEY, $number);
        config(['sis.bursary_doc_last' => $number]);
    }
}
