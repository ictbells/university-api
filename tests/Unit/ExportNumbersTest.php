<?php

namespace Tests\Unit;

use App\Support\ExportNumbers;
use PHPUnit\Framework\TestCase;

class ExportNumbersTest extends TestCase
{
    public function test_parses_formatted_naira_strings(): void
    {
        $this->assertSame(1234.5, ExportNumbers::toNumber('1,234.50'));
        $this->assertSame(1234.5, ExportNumbers::toNumber('NGN 1,234.50'));
        $this->assertSame(25000.0, ExportNumbers::toNumber(25000));
        $this->assertNull(ExportNumbers::toNumber('—'));
        $this->assertSame('1,234.50', ExportNumbers::display(1234.5));
    }

    public function test_detects_money_fields(): void
    {
        $this->assertTrue(ExportNumbers::isMoneyField('amount', 'Amount'));
        $this->assertTrue(ExportNumbers::isMoneyField('balance', 'Balance'));
        $this->assertFalse(ExportNumbers::isMoneyField('current_level', 'Level'));
    }
}
