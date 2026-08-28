<?php

namespace Tests\Unit;

use App\Support\AdmissionEntryRules;
use Tests\TestCase;

class AdmissionEntryRulesTest extends TestCase
{
    public function test_nabteb_cannot_be_combined_with_a_second_sitting(): void
    {
        $nabteb = [
            'exam_type' => 'NABTEB',
            'exam_center' => 'Abeokuta',
            'exam_year' => '2019',
            'exam_number' => 'N123',
            'results' => [['subject_id' => 1, 'grade' => 'C6']],
        ];
        $waec = [
            'exam_type' => 'WAEC',
            'exam_center' => 'Lagos',
            'exam_year' => '2018',
            'exam_number' => 'W123',
            'results' => [['subject_id' => 1, 'grade' => 'B3']],
        ];

        $neco = [
            'exam_type' => 'NECO',
            'exam_center' => 'Ibadan',
            'exam_year' => '2018',
            'exam_number' => 'E123',
            'results' => [['subject_id' => 1, 'grade' => 'C4']],
        ];

        $this->assertTrue(AdmissionEntryRules::nabtebCombinedWithSecondSitting($nabteb, $waec));
        $this->assertTrue(AdmissionEntryRules::nabtebCombinedWithSecondSitting($waec, $nabteb));
        $this->assertTrue(AdmissionEntryRules::nabtebCombinedWithSecondSitting($nabteb, $neco));
        $this->assertTrue(AdmissionEntryRules::nabtebCombinedWithSecondSitting($neco, $nabteb));
        $this->assertFalse(AdmissionEntryRules::nabtebCombinedWithSecondSitting($nabteb, null));
        $this->assertFalse(AdmissionEntryRules::nabtebCombinedWithSecondSitting($nabteb, []));
        $this->assertFalse(AdmissionEntryRules::nabtebCombinedWithSecondSitting($waec, $waec));
        $this->assertFalse(AdmissionEntryRules::nabtebCombinedWithSecondSitting($waec, $neco));
    }

    public function test_jupeb_does_not_allow_a_second_programme(): void
    {
        $this->assertFalse(AdmissionEntryRules::allowsSecondProgramme('jupeb'));
        $this->assertTrue(AdmissionEntryRules::allowsSecondProgramme('utme'));
        $this->assertTrue(AdmissionEntryRules::allowsSecondProgramme('de'));
    }

    public function test_jupeb_required_documents_are_passport_and_olevel(): void
    {
        $keys = collect(AdmissionEntryRules::requiredDocuments('jupeb'))->pluck('key')->all();
        $required = collect(AdmissionEntryRules::requiredDocuments('jupeb'))
            ->where('required', true)
            ->pluck('key')
            ->all();

        $this->assertSame(['passport', 'olevel_first_sitting', 'olevel_second_sitting'], $keys);
        $this->assertSame(['passport', 'olevel_first_sitting'], $required);
        $this->assertNotContains('birth_certificate', $keys);
        $this->assertNotContains('jamb_result', $keys);
    }

    public function test_utme_still_requires_birth_certificate_and_jamb(): void
    {
        $required = collect(AdmissionEntryRules::requiredDocuments('utme'))
            ->where('required', true)
            ->pluck('key')
            ->all();

        $this->assertContains('birth_certificate', $required);
        $this->assertContains('jamb_result', $required);
        $this->assertContains('olevel_first_sitting', $required);
    }
}
