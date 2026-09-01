<?php

namespace Tests\Unit;

use App\Support\BroadsheetStanding;
use PHPUnit\Framework\TestCase;

class BroadsheetStandingTest extends TestCase
{
    public function test_sanction_outranks_exam_remarks_and_gpa(): void
    {
        $this->assertSame('RUS', BroadsheetStanding::classify(5.0, 0, '2020', ['abs_p'], false, false, 'rusticated'));
        $this->assertSame('EXP', BroadsheetStanding::classify(5.0, 0, '2020', [], true, false, 'expelled'));
        $this->assertSame('SUS', BroadsheetStanding::classify(5.0, 0, '2020', [], true, false, 'suspended'));
        $this->assertSame('WD', BroadsheetStanding::classify(5.0, 0, '2020', [], true, false, 'withdrawn'));
    }

    public function test_unscored_remarks_map_to_summary_codes(): void
    {
        $this->assertSame('SICK', BroadsheetStanding::classify(null, 0, '2020', ['sick'], false, false));
        $this->assertSame('ABS_NP', BroadsheetStanding::classify(null, 0, '2020', ['abs_p', 'abs_np'], false, false));
        $this->assertSame('ABS_P', BroadsheetStanding::classify(null, 0, '2020', ['abs_p'], false, false));
        $this->assertSame('AR', BroadsheetStanding::classify(null, 0, '2020', ['ar'], false, true));
    }

    public function test_scored_papers_use_gs_unless_incomplete(): void
    {
        $this->assertSame('GS', BroadsheetStanding::classify(2.0, 0, '2020', ['abs_p'], true, false));
        $this->assertSame('AR', BroadsheetStanding::classify(2.0, 3, '2020', ['ar'], true, true));
        $this->assertSame('NGS', BroadsheetStanding::classify(1.2, 0, '2020', [], true, false));
    }
}
