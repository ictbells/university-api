<?php

namespace App\Support;

class HostelRoomImportColumns
{
    public const SHEET = 'Rooms';

    public const FILENAME = 'hostel-room-import-template.xlsx';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return ['hostel_id', 'block_id', 'number', 'capacity', 'gender', 'is_active'];
    }

    /**
     * @return list<string>
     */
    public static function required(): array
    {
        return ['hostel_id', 'block_id', 'number', 'capacity'];
    }

    /**
     * @return array<string, string>
     */
    public static function sample(): array
    {
        return [
            'hostel_id' => '1',
            'block_id' => '1',
            'number' => 'A101',
            'capacity' => '4',
            'gender' => 'female',
            'is_active' => 'yes',
        ];
    }

    /**
     * @return list<string>
     */
    public static function instructions(): array
    {
        return [
            'Import hostel rooms',
            '',
            'Hostels and blocks must already exist. This file creates rooms only. Beds are created from capacity.',
            'Matching rooms are skipped (same number in the same block). Existing rooms are not updated.',
            'Required: hostel_id, block_id, number, capacity (1–20).',
            'Optional: gender (male or female), is_active (yes/no, default yes).',
            'Copy hostel_id from the Hostels lookup id column and block_id from the Blocks lookup id column. The block must belong to that hostel.',
            'Do not paste data into the lookup sheets.',
        ];
    }
}
