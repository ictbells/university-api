<?php

namespace Tests\Unit;

use App\Support\NairaWords;
use PHPUnit\Framework\TestCase;

class NairaWordsTest extends TestCase
{
    public function test_formats_whole_naira_with_only(): void
    {
        $this->assertSame('Five Thousand Naira Only', NairaWords::phrase(5000));
    }

    public function test_includes_kobo(): void
    {
        $this->assertSame('Five Thousand Naira and Fifty Kobo Only', NairaWords::phrase(5000.50));
    }
}
