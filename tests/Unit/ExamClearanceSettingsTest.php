<?php

namespace Tests\Unit;

use App\Support\ExamClearanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamClearanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_and_reads_exam_clearance_conditions(): void
    {
        $defaults = ExamClearanceSettings::all();
        $this->assertTrue($defaults['tuition_paid']);
        $this->assertSame(100, $defaults['tuition_percent']);
        $this->assertFalse($defaults['hostel_if_allocated']);

        $updated = ExamClearanceSettings::update([
            'tuition_percent' => 70,
            'hostel_if_allocated' => true,
            'clinic_bills_cleared' => true,
            'courses_registered' => false,
        ]);

        $this->assertSame(70, $updated['tuition_percent']);
        $this->assertTrue($updated['hostel_if_allocated']);
        $this->assertTrue($updated['clinic_bills_cleared']);
        $this->assertFalse($updated['courses_registered']);
        $this->assertTrue($updated['tuition_paid']);
        $this->assertSame($updated, ExamClearanceSettings::all());
    }
}
