<?php

namespace Tests\Unit;

use App\Support\AdmissionsContactSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdmissionsContactSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_and_reads_admissions_contact(): void
    {
        $defaults = AdmissionsContactSettings::all();
        $this->assertSame('', $defaults['admissions_email']);
        $this->assertSame('', $defaults['admissions_phone']);

        $updated = AdmissionsContactSettings::update([
            'admissions_email' => ' admissions@example.edu.ng ',
            'admissions_phone' => ' +234 801 234 5678 ',
        ]);

        $this->assertSame('admissions@example.edu.ng', $updated['admissions_email']);
        $this->assertSame('+234 801 234 5678', $updated['admissions_phone']);
        $this->assertSame($updated, AdmissionsContactSettings::all());
        $this->assertSame(
            'admissions@example.edu.ng · +234 801 234 5678',
            \App\Models\Setting::getValue('university_contact')
        );
    }
}
