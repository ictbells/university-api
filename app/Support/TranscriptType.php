<?php

namespace App\Support;

class TranscriptType
{
    public const E_COPY = 'e_copy';

    public const WITHIN_NIGERIA = 'within_nigeria';

    public const OUTSIDE_NIGERIA = 'outside_nigeria';

    public const STUDENT_COPY = 'student_copy';

    public const ALL = [
        self::E_COPY,
        self::WITHIN_NIGERIA,
        self::OUTSIDE_NIGERIA,
        self::STUDENT_COPY,
    ];

    public const COLLECTION_COLLECT = 'collect';

    public const COLLECTION_POST = 'post';

    public const COLLECTION_METHODS = [
        self::COLLECTION_COLLECT,
        self::COLLECTION_POST,
    ];

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return [
            [
                'value' => self::E_COPY,
                'label' => 'E-copy',
                'description' => 'Signed PDF sent to an email address you provide.',
            ],
            [
                'value' => self::WITHIN_NIGERIA,
                'label' => 'Within Nigeria',
                'description' => 'Hard copy posted to an address in Nigeria.',
            ],
            [
                'value' => self::OUTSIDE_NIGERIA,
                'label' => 'Outside Nigeria',
                'description' => 'Hard copy posted to an address outside Nigeria.',
            ],
            [
                'value' => self::STUDENT_COPY,
                'label' => 'Student copy',
                'description' => 'Collect at the Registry or give a postal address.',
            ],
        ];
    }

    public static function label(string $type): string
    {
        foreach (self::options() as $option) {
            if ($option['value'] === $type) {
                return $option['label'];
            }
        }

        return $type;
    }

    public static function requiresEmail(string $type): bool
    {
        return $type === self::E_COPY;
    }

    public static function requiresAddress(string $type): bool
    {
        return in_array($type, [self::WITHIN_NIGERIA, self::OUTSIDE_NIGERIA], true);
    }

    public static function allowsCollection(string $type): bool
    {
        return $type === self::STUDENT_COPY;
    }
}
