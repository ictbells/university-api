<?php

namespace Tests\Unit;

use App\Support\StaffSupportContactSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffSupportContactSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_and_reads_staff_support_contact(): void
    {
        $defaults = StaffSupportContactSettings::all();
        $this->assertSame('ICT & Registry support', $defaults['staff_support_label']);
        $this->assertSame('ict@bellsuniversity.edu.ng', $defaults['staff_support_email']);
        $this->assertSame('+234 801 000 0000', $defaults['staff_support_phone']);

        $updated = StaffSupportContactSettings::update([
            'staff_support_label' => ' Helpdesk ',
            'staff_support_email' => ' ict@example.edu.ng ',
            'staff_support_phone' => ' +234 801 234 5678 ',
        ]);

        $this->assertSame('Helpdesk', $updated['staff_support_label']);
        $this->assertSame('ict@example.edu.ng', $updated['staff_support_email']);
        $this->assertSame('+234 801 234 5678', $updated['staff_support_phone']);
        $this->assertSame($updated, StaffSupportContactSettings::all());
    }

    public function test_blank_label_falls_back_to_default(): void
    {
        $updated = StaffSupportContactSettings::update([
            'staff_support_label' => '   ',
        ]);

        $this->assertSame('ICT & Registry support', $updated['staff_support_label']);
    }
}
