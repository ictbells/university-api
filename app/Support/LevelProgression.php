<?php

namespace App\Support;

use App\Models\Program;

class LevelProgression
{
    public static function isPostgraduate(Program $program): bool
    {
        return $program->study_level === 'postgraduate';
    }

    public static function normalizeLevel(int $level, Program $program): int
    {
        if (self::isPostgraduate($program)) {
            return max(1, $level);
        }

        return $level < 100 ? $level * 100 : $level;
    }

    public static function finalLevelForProgram(Program $program): int
    {
        $years = max(1, (int) $program->duration_years);

        return self::isPostgraduate($program) ? $years : $years * 100;
    }

    public static function stepForProgram(Program $program): int
    {
        return self::isPostgraduate($program) ? 1 : 100;
    }

    public static function nextLevel(int $currentLevel, Program $program): ?int
    {
        $current = self::normalizeLevel($currentLevel, $program);
        $final = self::finalLevelForProgram($program);

        if ($current >= $final) {
            return null;
        }

        return min($current + self::stepForProgram($program), $final);
    }
}
