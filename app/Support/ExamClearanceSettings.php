<?php

namespace App\Support;

use App\Models\Setting;

class ExamClearanceSettings
{
    public const KEY = 'exam_clearance';

    public static function defaults(): array
    {
        return [
            'tuition_paid' => true,
            'tuition_percent' => 100,
            'courses_registered' => true,
            'no_outstanding_invoices' => true,
            'hostel_if_allocated' => false,
            'clinic_bills_cleared' => false,
        ];
    }

    public static function all(): array
    {
        $stored = Setting::getValue(self::KEY);
        $decoded = is_string($stored) ? json_decode($stored, true) : (is_array($stored) ? $stored : []);
        if (! is_array($decoded)) {
            $decoded = [];
        }

        $defaults = self::defaults();

        return [
            'tuition_paid' => array_key_exists('tuition_paid', $decoded) ? (bool) $decoded['tuition_paid'] : $defaults['tuition_paid'],
            'tuition_percent' => max(0, min(100, (int) ($decoded['tuition_percent'] ?? $defaults['tuition_percent']))),
            'courses_registered' => array_key_exists('courses_registered', $decoded) ? (bool) $decoded['courses_registered'] : $defaults['courses_registered'],
            'no_outstanding_invoices' => array_key_exists('no_outstanding_invoices', $decoded) ? (bool) $decoded['no_outstanding_invoices'] : $defaults['no_outstanding_invoices'],
            'hostel_if_allocated' => array_key_exists('hostel_if_allocated', $decoded) ? (bool) $decoded['hostel_if_allocated'] : $defaults['hostel_if_allocated'],
            'clinic_bills_cleared' => array_key_exists('clinic_bills_cleared', $decoded) ? (bool) $decoded['clinic_bills_cleared'] : $defaults['clinic_bills_cleared'],
        ];
    }

    public static function update(array $data): array
    {
        $current = self::all();
        if (array_key_exists('tuition_paid', $data)) {
            $current['tuition_paid'] = (bool) $data['tuition_paid'];
        }
        if (array_key_exists('tuition_percent', $data)) {
            $current['tuition_percent'] = max(0, min(100, (int) $data['tuition_percent']));
        }
        if (array_key_exists('courses_registered', $data)) {
            $current['courses_registered'] = (bool) $data['courses_registered'];
        }
        if (array_key_exists('no_outstanding_invoices', $data)) {
            $current['no_outstanding_invoices'] = (bool) $data['no_outstanding_invoices'];
        }
        if (array_key_exists('hostel_if_allocated', $data)) {
            $current['hostel_if_allocated'] = (bool) $data['hostel_if_allocated'];
        }
        if (array_key_exists('clinic_bills_cleared', $data)) {
            $current['clinic_bills_cleared'] = (bool) $data['clinic_bills_cleared'];
        }

        Setting::setValue(self::KEY, json_encode($current));

        return $current;
    }
}
