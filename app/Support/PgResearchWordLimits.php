<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

class PgResearchWordLimits
{
    public const RESEARCH_MIN = 'admissions.pg_research_interest_min_words';

    public const RESEARCH_MAX = 'admissions.pg_research_interest_max_words';

    public const PURPOSE_MIN = 'admissions.pg_statement_of_purpose_min_words';

    public const PURPOSE_MAX = 'admissions.pg_statement_of_purpose_max_words';

    /**
     * @return array{
     *   pg_research_interest_min_words: int,
     *   pg_research_interest_max_words: int,
     *   pg_statement_of_purpose_min_words: int,
     *   pg_statement_of_purpose_max_words: int
     * }
     */
    public static function defaults(): array
    {
        return [
            'pg_research_interest_min_words' => 0,
            'pg_research_interest_max_words' => 150,
            'pg_statement_of_purpose_min_words' => 0,
            'pg_statement_of_purpose_max_words' => 500,
        ];
    }

    /**
     * @return array{
     *   pg_research_interest_min_words: int,
     *   pg_research_interest_max_words: int,
     *   pg_statement_of_purpose_min_words: int,
     *   pg_statement_of_purpose_max_words: int
     * }
     */
    public static function all(): array
    {
        $defaults = self::defaults();

        return self::normalize([
            'pg_research_interest_min_words' => (int) Setting::getValue(self::RESEARCH_MIN, $defaults['pg_research_interest_min_words']),
            'pg_research_interest_max_words' => (int) Setting::getValue(self::RESEARCH_MAX, $defaults['pg_research_interest_max_words']),
            'pg_statement_of_purpose_min_words' => (int) Setting::getValue(self::PURPOSE_MIN, $defaults['pg_statement_of_purpose_min_words']),
            'pg_statement_of_purpose_max_words' => (int) Setting::getValue(self::PURPOSE_MAX, $defaults['pg_statement_of_purpose_max_words']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *   pg_research_interest_min_words: int,
     *   pg_research_interest_max_words: int,
     *   pg_statement_of_purpose_min_words: int,
     *   pg_statement_of_purpose_max_words: int
     * }
     */
    public static function update(array $data): array
    {
        $current = self::all();
        foreach (array_keys(self::defaults()) as $key) {
            if (array_key_exists($key, $data)) {
                $current[$key] = (int) $data[$key];
            }
        }
        $current = self::normalize($current);

        Setting::setValue(self::RESEARCH_MIN, (string) $current['pg_research_interest_min_words']);
        Setting::setValue(self::RESEARCH_MAX, (string) $current['pg_research_interest_max_words']);
        Setting::setValue(self::PURPOSE_MIN, (string) $current['pg_statement_of_purpose_min_words']);
        Setting::setValue(self::PURPOSE_MAX, (string) $current['pg_statement_of_purpose_max_words']);

        return $current;
    }

    public static function wordCount(?string $text): int
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        if ($trimmed === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertPayload(array $payload, string $errorPrefix = 'payload'): void
    {
        $limits = self::all();
        $prefix = $errorPrefix === '' ? '' : rtrim($errorPrefix, '.').'.';

        self::assertField(
            $prefix.'research_interest',
            isset($payload['research_interest']) ? (string) $payload['research_interest'] : '',
            $limits['pg_research_interest_min_words'],
            $limits['pg_research_interest_max_words'],
            'Research interest',
        );
        self::assertField(
            $prefix.'statement_of_purpose',
            isset($payload['statement_of_purpose']) ? (string) $payload['statement_of_purpose'] : '',
            $limits['pg_statement_of_purpose_min_words'],
            $limits['pg_statement_of_purpose_max_words'],
            'Statement of purpose',
        );
    }

    public static function charMax(int $existing, int $maxWords): int
    {
        if ($maxWords < 1) {
            return $existing;
        }

        return max($existing, $maxWords * 20);
    }

    /**
     * @param  array{
     *   pg_research_interest_min_words: int,
     *   pg_research_interest_max_words: int,
     *   pg_statement_of_purpose_min_words: int,
     *   pg_statement_of_purpose_max_words: int
     * }  $limits
     * @return array{
     *   pg_research_interest_min_words: int,
     *   pg_research_interest_max_words: int,
     *   pg_statement_of_purpose_min_words: int,
     *   pg_statement_of_purpose_max_words: int
     * }
     */
    private static function normalize(array $limits): array
    {
        foreach (['pg_research_interest_min_words', 'pg_research_interest_max_words', 'pg_statement_of_purpose_min_words', 'pg_statement_of_purpose_max_words'] as $key) {
            $limits[$key] = max(0, min(5000, (int) ($limits[$key] ?? 0)));
        }
        if ($limits['pg_research_interest_max_words'] > 0) {
            $limits['pg_research_interest_min_words'] = min(
                $limits['pg_research_interest_min_words'],
                $limits['pg_research_interest_max_words'],
            );
        }
        if ($limits['pg_statement_of_purpose_max_words'] > 0) {
            $limits['pg_statement_of_purpose_min_words'] = min(
                $limits['pg_statement_of_purpose_min_words'],
                $limits['pg_statement_of_purpose_max_words'],
            );
        }

        return $limits;
    }

    private static function assertField(string $key, string $value, int $min, int $max, string $label): void
    {
        $count = self::wordCount($value);
        if ($count === 0) {
            return;
        }
        if ($min > 0 && $count < $min) {
            throw ValidationException::withMessages([
                $key => ["{$label} must be at least {$min} words (currently {$count})."],
            ]);
        }
        if ($max > 0 && $count > $max) {
            throw ValidationException::withMessages([
                $key => ["{$label} must be at most {$max} words (currently {$count})."],
            ]);
        }
    }
}
