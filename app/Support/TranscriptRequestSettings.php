<?php

namespace App\Support;

use App\Models\Setting;
use InvalidArgumentException;

class TranscriptRequestSettings
{
    public const ENABLED = 'transcript.requests_enabled';

    public const DELIVERY_COLLECT = 'transcript.delivery_collect';

    public const DELIVERY_GENERATED = 'transcript.delivery_generated_pdf';

    public const DELIVERY_UPLOADED = 'transcript.delivery_uploaded_pdf';

    public const COLLECT_INSTRUCTIONS = 'transcript.collect_instructions';

    public const REGISTRAR_NAME = 'registrar_name';

    public const REGISTRAR_TITLE = 'registrar_title';

    public static function defaults(): array
    {
        return [
            'transcript_requests_enabled' => false,
            'transcript_delivery_collect' => true,
            'transcript_delivery_generated_pdf' => true,
            'transcript_delivery_uploaded_pdf' => true,
            'transcript_collect_instructions' => 'Please collect your official transcript from the Registry during office hours. Bring a valid ID and your request reference.',
            'registrar_name' => '',
            'registrar_title' => 'Registrar',
        ];
    }

    public static function all(): array
    {
        $defaults = self::defaults();

        return [
            'transcript_requests_enabled' => Setting::getValue(self::ENABLED, '0') === '1',
            'transcript_delivery_collect' => Setting::getValue(self::DELIVERY_COLLECT, $defaults['transcript_delivery_collect'] ? '1' : '0') === '1',
            'transcript_delivery_generated_pdf' => Setting::getValue(self::DELIVERY_GENERATED, $defaults['transcript_delivery_generated_pdf'] ? '1' : '0') === '1',
            'transcript_delivery_uploaded_pdf' => Setting::getValue(self::DELIVERY_UPLOADED, $defaults['transcript_delivery_uploaded_pdf'] ? '1' : '0') === '1',
            'transcript_collect_instructions' => trim((string) Setting::getValue(
                self::COLLECT_INSTRUCTIONS,
                $defaults['transcript_collect_instructions'],
            )) ?: $defaults['transcript_collect_instructions'],
            'registrar_name' => trim((string) Setting::getValue(self::REGISTRAR_NAME, $defaults['registrar_name'])),
            'registrar_title' => trim((string) Setting::getValue(self::REGISTRAR_TITLE, $defaults['registrar_title']))
                ?: $defaults['registrar_title'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function enabledDeliveryModes(): array
    {
        $all = self::all();
        $modes = [];
        if ($all['transcript_delivery_collect']) {
            $modes[] = 'collect';
        }
        if ($all['transcript_delivery_generated_pdf']) {
            $modes[] = 'generated_pdf';
        }
        if ($all['transcript_delivery_uploaded_pdf']) {
            $modes[] = 'uploaded_pdf';
        }

        return $modes;
    }

    public static function enabled(): bool
    {
        return self::all()['transcript_requests_enabled'] === true
            && count(self::enabledDeliveryModes()) > 0;
    }

    public static function update(array $data): array
    {
        $current = self::all();

        if (array_key_exists('transcript_requests_enabled', $data)) {
            $current['transcript_requests_enabled'] = (bool) $data['transcript_requests_enabled'];
        }
        if (array_key_exists('transcript_delivery_collect', $data)) {
            $current['transcript_delivery_collect'] = (bool) $data['transcript_delivery_collect'];
        }
        if (array_key_exists('transcript_delivery_generated_pdf', $data)) {
            $current['transcript_delivery_generated_pdf'] = (bool) $data['transcript_delivery_generated_pdf'];
        }
        if (array_key_exists('transcript_delivery_uploaded_pdf', $data)) {
            $current['transcript_delivery_uploaded_pdf'] = (bool) $data['transcript_delivery_uploaded_pdf'];
        }
        if (array_key_exists('transcript_collect_instructions', $data)) {
            $text = trim((string) $data['transcript_collect_instructions']);
            $current['transcript_collect_instructions'] = $text !== ''
                ? $text
                : self::defaults()['transcript_collect_instructions'];
        }
        if (array_key_exists('registrar_name', $data)) {
            $current['registrar_name'] = trim((string) $data['registrar_name']);
        }
        if (array_key_exists('registrar_title', $data)) {
            $title = trim((string) $data['registrar_title']);
            $current['registrar_title'] = $title !== '' ? $title : self::defaults()['registrar_title'];
        }

        if (
            $current['transcript_requests_enabled']
            && ! $current['transcript_delivery_collect']
            && ! $current['transcript_delivery_generated_pdf']
            && ! $current['transcript_delivery_uploaded_pdf']
        ) {
            throw new InvalidArgumentException('Enable at least one delivery mode when transcript requests are on.');
        }

        Setting::setValue(self::ENABLED, $current['transcript_requests_enabled'] ? '1' : '0');
        Setting::setValue(self::DELIVERY_COLLECT, $current['transcript_delivery_collect'] ? '1' : '0');
        Setting::setValue(self::DELIVERY_GENERATED, $current['transcript_delivery_generated_pdf'] ? '1' : '0');
        Setting::setValue(self::DELIVERY_UPLOADED, $current['transcript_delivery_uploaded_pdf'] ? '1' : '0');
        Setting::setValue(self::COLLECT_INSTRUCTIONS, $current['transcript_collect_instructions']);
        Setting::setValue(self::REGISTRAR_NAME, $current['registrar_name']);
        Setting::setValue(self::REGISTRAR_TITLE, $current['registrar_title']);

        return self::all();
    }
}
