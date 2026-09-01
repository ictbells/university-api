<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Setting;
use App\Models\Student;
use App\Support\DotenvWriter;
use Illuminate\Support\Facades\DB;

class MatricSequence
{
    public const SETTING_KEY = 'matric_last';

    public function allocate(?Application $application = null): string
    {
        $year = $this->year($application);

        return DB::transaction(function () use ($year) {
            $this->lock();
            $serial = $this->nextSerial($year);
            $matric = $this->format($year, $serial);
            while ($this->taken($matric)) {
                $serial++;
                $matric = $this->format($year, $serial);
            }
            $this->persist($matric);

            return $matric;
        });
    }

    public function noteIssued(string $matric): void
    {
        $parsed = $this->parse($matric);
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

    public function year(?Application $application = null): int
    {
        $override = (int) config('sis.matric_year');
        if ($override >= 2000 && $override <= 2100) {
            return $override;
        }

        $label = (string) ($application?->sessionLabel() ?? '');
        if (preg_match('/(20\d{2})/', $label, $match)) {
            return (int) $match[1];
        }

        return (int) now()->format('Y');
    }

    /**
     * @return array{year: int, serial: int}|null
     */
    public function parse(string $value): ?array
    {
        $value = strtoupper(trim(str_replace(' ', '', $value)));
        if (! preg_match('/^(20\d{2})\/(\d+)$/', $value, $match)) {
            return null;
        }

        return [
            'year' => (int) $match[1],
            'serial' => (int) $match[2],
        ];
    }

    public function format(int $year, int $serial): string
    {
        $digits = max(1, (int) config('sis.matric_digits', 6));

        return $year.'/'.str_pad((string) $serial, $digits, '0', STR_PAD_LEFT);
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

        $dbMax = $this->maxSerialInDatabase($year);
        if ($dbMax > $best) {
            return $this->format($year, $dbMax);
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
            (string) config('sis.matric_last'),
            (string) ($_ENV['MATRIC_LAST'] ?? getenv('MATRIC_LAST') ?: ''),
        ]));
    }

    private function maxSerialInDatabase(int $year): int
    {
        $prefix = $year.'/';
        $max = 0;
        $rows = Student::query()
            ->where(function ($query) use ($prefix) {
                $query->where('matric_number', 'like', $prefix.'%')
                    ->orWhere('student_number', 'like', $prefix.'%');
            })
            ->get(['matric_number', 'student_number']);

        foreach ($rows as $row) {
            foreach ([(string) $row->matric_number, (string) $row->student_number] as $value) {
                $parsed = $this->parse($value);
                if ($parsed && $parsed['year'] === $year) {
                    $max = max($max, $parsed['serial']);
                }
            }
        }

        return $max;
    }

    private function taken(string $matric): bool
    {
        return Student::query()
            ->where(function ($query) use ($matric) {
                $query->where('matric_number', $matric)
                    ->orWhere('student_number', $matric);
            })
            ->exists();
    }

    private function persist(string $matric): void
    {
        Setting::setValue(self::SETTING_KEY, $matric);
        config(['sis.matric_last' => $matric]);
        if (! app()->environment('testing')) {
            DotenvWriter::set('MATRIC_LAST', $matric);
        }
    }
}
