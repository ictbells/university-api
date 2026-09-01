<?php

namespace Tests\Unit;

use App\Services\MatricSequence;
use App\Support\DotenvWriter;
use Tests\TestCase;

class MatricSequenceTest extends TestCase
{
    public function test_parse_and_format_year_slash_serial(): void
    {
        $sequence = app(MatricSequence::class);

        $this->assertSame(['year' => 2026, 'serial' => 1], $sequence->parse('2026/000001'));
        $this->assertSame('2026/000001', $sequence->format(2026, 1));
        $this->assertNull($sequence->parse('BUT/2026/M/0001'));
    }

    public function test_dotenv_writer_updates_or_appends_matric_last(): void
    {
        $path = sys_get_temp_dir().'/bells-matric-'.uniqid().'.env';
        file_put_contents($path, "APP_KEY=test\nMATRIC_LAST=2026/000010\n");

        $this->assertTrue(DotenvWriter::set('MATRIC_LAST', '2026/000011', $path));
        $contents = str_replace("\r", '', (string) file_get_contents($path));
        $this->assertStringContainsString('MATRIC_LAST=2026/000011', $contents);
        $this->assertStringNotContainsString('MATRIC_LAST=2026/000010', $contents);

        $this->assertTrue(DotenvWriter::set('MATRIC_YEAR', '2026', $path));
        $this->assertStringContainsString('MATRIC_YEAR=2026', str_replace("\r", '', (string) file_get_contents($path)));

        unlink($path);
    }
}
