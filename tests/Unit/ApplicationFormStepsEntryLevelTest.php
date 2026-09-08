<?php

namespace Tests\Unit;

use App\Support\ApplicationFormSteps;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApplicationFormStepsEntryLevelTest extends TestCase
{
    public function test_normalize_entry_level_defaults_blank_and_strips_label(): void
    {
        $this->assertSame('200', ApplicationFormSteps::normalizeEntryLevel(null, ApplicationFormSteps::DE_ENTRY_LEVELS, '200'));
        $this->assertSame('200', ApplicationFormSteps::normalizeEntryLevel('', ApplicationFormSteps::DE_ENTRY_LEVELS, '200'));
        $this->assertSame('300', ApplicationFormSteps::normalizeEntryLevel('300', ApplicationFormSteps::DE_ENTRY_LEVELS, '200'));
        $this->assertSame('200', ApplicationFormSteps::normalizeEntryLevel('200 Level', ApplicationFormSteps::DE_ENTRY_LEVELS, '200'));
        $this->assertSame('200', ApplicationFormSteps::normalizeEntryLevel('999', ApplicationFormSteps::DE_ENTRY_LEVELS, '200'));
    }

    public function test_validate_direct_entry_accepts_blank_requested_level_as_200(): void
    {
        $payload = ApplicationFormSteps::validateDirectEntry(Request::create('/'), [
            'previous_institution' => 'OGITECH',
            'qualification_type' => 'nd',
            'qualification_title' => 'ND Computer Science',
            'qualification_class' => 'upper_credit',
            'qualification_year' => '2025',
            'programme' => 'computer science',
            'requested_entry_level' => '',
        ]);

        $this->assertSame('200', $payload['requested_entry_level']);
    }
}
