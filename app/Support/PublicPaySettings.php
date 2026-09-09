<?php

namespace App\Support;

use App\Models\Setting;

class PublicPaySettings
{
    public const ENABLED = 'public_pay.enabled';

    public const COLLECT_INSTRUCTIONS = 'public_pay.collect_instructions';

    public static function defaults(): array
    {
        return [
            'public_pay_enabled' => false,
            'public_pay_collect_instructions' => 'Please collect your document from the relevant office during office hours. Bring a valid ID and your request reference.',
        ];
    }

    public static function all(): array
    {
        $defaults = self::defaults();

        return [
            'public_pay_enabled' => Setting::getValue(self::ENABLED, '0') === '1',
            'public_pay_collect_instructions' => trim((string) Setting::getValue(
                self::COLLECT_INSTRUCTIONS,
                $defaults['public_pay_collect_instructions'],
            )) ?: $defaults['public_pay_collect_instructions'],
        ];
    }

    public static function enabled(): bool
    {
        return self::all()['public_pay_enabled'] === true;
    }

    public static function update(array $data): array
    {
        $current = self::all();

        if (array_key_exists('public_pay_enabled', $data)) {
            $current['public_pay_enabled'] = (bool) $data['public_pay_enabled'];
        }
        if (array_key_exists('public_pay_collect_instructions', $data)) {
            $current['public_pay_collect_instructions'] = trim((string) $data['public_pay_collect_instructions'])
                ?: self::defaults()['public_pay_collect_instructions'];
        }

        Setting::setValue(self::ENABLED, $current['public_pay_enabled'] ? '1' : '0');
        Setting::setValue(self::COLLECT_INSTRUCTIONS, $current['public_pay_collect_instructions']);

        return self::all();
    }
}
