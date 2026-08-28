<?php

namespace Tests\Unit;

use App\Support\PgResearchWordLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PgResearchWordLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_reads_and_clamps_limits(): void
    {
        $defaults = PgResearchWordLimits::all();
        $this->assertSame(0, $defaults['pg_research_interest_min_words']);
        $this->assertSame(150, $defaults['pg_research_interest_max_words']);
        $this->assertSame(0, $defaults['pg_statement_of_purpose_min_words']);
        $this->assertSame(500, $defaults['pg_statement_of_purpose_max_words']);

        $updated = PgResearchWordLimits::update([
            'pg_research_interest_min_words' => 200,
            'pg_research_interest_max_words' => 100,
            'pg_statement_of_purpose_min_words' => 80,
            'pg_statement_of_purpose_max_words' => 300,
        ]);

        $this->assertSame(100, $updated['pg_research_interest_min_words']);
        $this->assertSame(100, $updated['pg_research_interest_max_words']);
        $this->assertSame(80, $updated['pg_statement_of_purpose_min_words']);
        $this->assertSame(300, $updated['pg_statement_of_purpose_max_words']);
        $this->assertSame($updated, PgResearchWordLimits::all());

        $clamped = PgResearchWordLimits::update([
            'pg_research_interest_max_words' => 99999,
        ]);
        $this->assertSame(5000, $clamped['pg_research_interest_max_words']);
    }

    public function test_word_count_collapses_whitespace(): void
    {
        $this->assertSame(0, PgResearchWordLimits::wordCount('   '));
        $this->assertSame(3, PgResearchWordLimits::wordCount("one\n  two\tthree"));
    }

    public function test_assert_payload_rejects_over_default_research_max(): void
    {
        $this->expectException(ValidationException::class);
        PgResearchWordLimits::assertPayload([
            'research_interest' => implode(' ', array_fill(0, 151, 'word')),
            'statement_of_purpose' => 'I want to study computing.',
        ]);
    }

    public function test_assert_payload_skips_empty_text(): void
    {
        PgResearchWordLimits::update([
            'pg_research_interest_min_words' => 10,
            'pg_research_interest_max_words' => 150,
        ]);

        PgResearchWordLimits::assertPayload([
            'research_interest' => '',
            'statement_of_purpose' => 'I want to study computing.',
        ]);

        $this->expectException(ValidationException::class);
        PgResearchWordLimits::assertPayload([
            'research_interest' => 'one two three',
            'statement_of_purpose' => 'I want to study computing.',
        ]);
    }

    public function test_char_max_uses_the_higher_of_existing_and_word_budget(): void
    {
        $this->assertSame(2000, PgResearchWordLimits::charMax(2000, 0));
        $this->assertSame(3000, PgResearchWordLimits::charMax(2000, 150));
        $this->assertSame(2000, PgResearchWordLimits::charMax(2000, 50));
    }
}
