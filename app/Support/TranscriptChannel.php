<?php

namespace App\Support;

use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;

class TranscriptChannel
{
    public const UNDERGRADUATE = 'undergraduate';

    public const JUPEB = 'jupeb';

    public const POSTGRADUATE = 'postgraduate';

    public const KEYS = [
        self::UNDERGRADUATE,
        self::JUPEB,
        self::POSTGRADUATE,
    ];

    /**
     * @return list<array{key: string, label: string, nav_key: string, path: string, title: string}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => self::UNDERGRADUATE,
                'label' => 'Undergraduate',
                'nav_key' => 'transcript-undergraduate',
                'path' => '/transcript-requests/undergraduate',
                'title' => 'Undergraduate transcript requests',
            ],
            [
                'key' => self::JUPEB,
                'label' => 'JUPEB',
                'nav_key' => 'transcript-jupeb',
                'path' => '/transcript-requests/jupeb',
                'title' => 'JUPEB transcript requests',
            ],
            [
                'key' => self::POSTGRADUATE,
                'label' => 'Postgraduate',
                'nav_key' => 'transcript-postgraduate',
                'path' => '/transcript-requests/postgraduate',
                'title' => 'Postgraduate transcript requests',
            ],
        ];
    }

    public static function isValid(?string $channel): bool
    {
        return in_array((string) $channel, self::KEYS, true);
    }

    public static function label(string $channel): string
    {
        foreach (self::all() as $row) {
            if ($row['key'] === $channel) {
                return $row['label'];
            }
        }

        return $channel;
    }

    public static function matches(Program $program, string $channel): bool
    {
        $modes = $program->entryModeList();
        $studyLevel = strtolower((string) $program->study_level);

        return match ($channel) {
            self::POSTGRADUATE => $studyLevel === 'postgraduate' || in_array('pg', $modes, true),
            self::JUPEB => $studyLevel === 'jupeb' || in_array('jupeb', $modes, true),
            self::UNDERGRADUATE => $studyLevel === 'undergraduate'
                && ! in_array('jupeb', $modes, true)
                && ! in_array('pg', $modes, true),
            default => false,
        };
    }

    public static function forProgram(Program $program): string
    {
        foreach (self::KEYS as $channel) {
            if (self::matches($program, $channel)) {
                return $channel;
            }
        }

        return self::UNDERGRADUATE;
    }

    public static function applyToProgramsQuery(Builder $query, string $channel): Builder
    {
        return match ($channel) {
            self::POSTGRADUATE => $query->where(function (Builder $q) {
                $q->where('study_level', 'postgraduate')
                    ->orWhereJsonContains('entry_modes', 'pg');
            }),
            self::JUPEB => $query->where(function (Builder $q) {
                $q->where('study_level', 'jupeb')
                    ->orWhereJsonContains('entry_modes', 'jupeb');
            }),
            self::UNDERGRADUATE => $query->where('study_level', 'undergraduate')
                ->where(function (Builder $q) {
                    $q->whereNull('entry_modes')
                        ->orWhere('entry_modes', '[]')
                        ->orWhere('entry_modes', '')
                        ->orWhere(function (Builder $inner) {
                            $inner->whereJsonDoesntContain('entry_modes', 'jupeb')
                                ->whereJsonDoesntContain('entry_modes', 'pg');
                        });
                }),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
