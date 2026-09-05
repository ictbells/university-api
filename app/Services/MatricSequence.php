<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Setting;
use App\Models\Student;
use App\Support\DotenvWriter;
use App\Support\StudyLevel;
use Illuminate\Support\Facades\DB;

class MatricSequence
{
    public const TRACK_UNDERGRADUATE = 'undergraduate';

    public const TRACK_POSTGRADUATE = 'postgraduate';

    public const SETTING_KEY = 'matric_last';

    public const PG_SETTING_KEY = 'pg_matric_last';

    public function allocate(?Application $application = null): string
    {
        $track = $this->trackFor($application);
        $year = $this->year($application, $track);

        return DB::transaction(function () use ($year, $track) {
            $this->lock($track);
            $serial = $this->nextSerial($year, $track);
            $matric = $this->format($year, $serial);
            while ($this->taken($matric)) {
                $serial++;
                $matric = $this->format($year, $serial);
            }
            $this->persist($matric, $track);

            return $matric;
        });
    }

    public function noteIssued(string $matric, ?Application $application = null): void
    {
        $parsed = $this->parse($matric);
        if (! $parsed) {
            return;
        }

        $track = $this->trackFor($application);

        DB::transaction(function () use ($parsed, $track) {
            $this->lock($track);
            $current = $this->parse($this->highestKnown($parsed['year'], $track));
            if ($current
                && (int) $current['year'] === $parsed['year']
                && (int) $current['serial'] >= $parsed['serial']
            ) {
                return;
            }
            $this->persist($this->format($parsed['year'], $parsed['serial']), $track);
        });
    }

    public function year(?Application $application = null, ?string $track = null): int
    {
        $track ??= $this->trackFor($application);
        $overrideKey = $track === self::TRACK_POSTGRADUATE ? 'sis.pg_matric_year' : 'sis.matric_year';
        $override = (int) config($overrideKey);
        if ($override < 2000 || $override > 2100) {
            // PG year can fall back to the shared undergraduate year override.
            $override = (int) config('sis.matric_year');
        }
        if ($override >= 2000 && $override <= 2100) {
            return $override;
        }

        $label = (string) ($application?->sessionLabel() ?? '');
        if (preg_match('/(20\d{2})/', $label, $match)) {
            return (int) $match[1];
        }

        return (int) now()->format('Y');
    }

    public function trackFor(?Application $application = null): string
    {
        if ($application && strtolower((string) $application->entry_mode) === 'pg') {
            return self::TRACK_POSTGRADUATE;
        }

        if ($application?->program_id) {
            $application->loadMissing('program');
            if ($application->program && StudyLevel::ofProgram($application->program) === StudyLevel::POSTGRADUATE) {
                return self::TRACK_POSTGRADUATE;
            }
        }

        return self::TRACK_UNDERGRADUATE;
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

    private function settingKey(string $track): string
    {
        return $track === self::TRACK_POSTGRADUATE ? self::PG_SETTING_KEY : self::SETTING_KEY;
    }

    private function envKey(string $track): string
    {
        return $track === self::TRACK_POSTGRADUATE ? 'PG_MATRIC_LAST' : 'MATRIC_LAST';
    }

    private function configLastKey(string $track): string
    {
        return $track === self::TRACK_POSTGRADUATE ? 'sis.pg_matric_last' : 'sis.matric_last';
    }

    private function lock(string $track): void
    {
        $key = $this->settingKey($track);
        Setting::query()->firstOrCreate(['key' => $key], ['value' => '']);
        Setting::query()->where('key', $key)->lockForUpdate()->first();
    }

    private function nextSerial(int $year, string $track): int
    {
        return $this->highestSerial($year, $track) + 1;
    }

    private function highestSerial(int $year, string $track): int
    {
        $parsed = $this->parse($this->highestKnown($year, $track));

        return $parsed && $parsed['year'] === $year ? $parsed['serial'] : 0;
    }

    private function highestKnown(int $year, string $track): string
    {
        $best = 0;
        $bestValue = '';
        foreach ($this->knownValues($track) as $value) {
            $parsed = $this->parse($value);
            if (! $parsed || $parsed['year'] !== $year) {
                continue;
            }
            if ($parsed['serial'] >= $best) {
                $best = $parsed['serial'];
                $bestValue = $this->format($parsed['year'], $parsed['serial']);
            }
        }

        $dbMax = $this->maxSerialInDatabase($year, $track);
        if ($dbMax > $best) {
            return $this->format($year, $dbMax);
        }

        return $bestValue;
    }

    /**
     * @return list<string>
     */
    private function knownValues(string $track): array
    {
        $values = [
            (string) Setting::query()->where('key', $this->settingKey($track))->value('value'),
            (string) config($this->configLastKey($track)),
        ];
        if (! $this->runningTests()) {
            $values[] = (string) ($_ENV[$this->envKey($track)] ?? getenv($this->envKey($track)) ?: '');
        }

        return array_values(array_filter($values));
    }

    private function maxSerialInDatabase(int $year, string $track): int
    {
        $prefix = $year.'/';
        $max = 0;
        $query = Student::query()
            ->where(function ($builder) use ($prefix) {
                $builder->where('matric_number', 'like', $prefix.'%')
                    ->orWhere('student_number', 'like', $prefix.'%');
            });

        if ($track === self::TRACK_POSTGRADUATE) {
            $query->where(function ($builder) {
                $builder->where('study_level', StudyLevel::POSTGRADUATE)
                    ->orWhereHas('application', fn ($apps) => $apps->where('entry_mode', 'pg'));
            });
        } else {
            $query->where(function ($builder) {
                $builder->where(function ($inner) {
                    $inner->whereNull('study_level')
                        ->orWhereNotIn('study_level', [StudyLevel::POSTGRADUATE, StudyLevel::JUPEB]);
                })->whereDoesntHave('application', fn ($apps) => $apps->whereIn('entry_mode', ['pg', 'jupeb']));
            });
        }

        $rows = $query->get(['matric_number', 'student_number']);

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

    private function persist(string $matric, string $track): void
    {
        Setting::setValue($this->settingKey($track), $matric);
        config([$this->configLastKey($track) => $matric]);
        if (! $this->runningTests()) {
            DotenvWriter::set($this->envKey($track), $matric);
        }
    }

    private function runningTests(): bool
    {
        return app()->environment('testing')
            || app()->runningUnitTests()
            || defined('PHPUNIT_COMPOSER_INSTALL');
    }
}
