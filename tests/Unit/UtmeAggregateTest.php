<?php

namespace Tests\Unit;

use App\Support\ApplicationFormSteps;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UtmeAggregateTest extends TestCase
{
    public function test_it_accepts_subject_scores_that_match_the_aggregate(): void
    {
        $payload = $this->payload('250', [65, 70, 58, 57]);
        $result = ApplicationFormSteps::validateUtme($this->request($payload), $payload, true);

        $this->assertEquals(250, $result['utme']['aggregate']);
        $this->assertCount(4, $result['utme']['subjects']);
        $this->assertArrayNotHasKey('course_choice', $result['utme']);
        $this->assertArrayNotHasKey('institution_choices', $result['utme']);
    }

    public function test_it_rejects_subject_scores_that_do_not_match_the_aggregate(): void
    {
        $payload = $this->payload('250', [65, 70, 58, 50]);

        try {
            ApplicationFormSteps::validateUtme($this->request($payload), $payload, true);
            $this->fail('Expected validation to fail when scores do not match the aggregate.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payload.utme.aggregate', $e->errors());
        }
    }

    public function test_it_does_not_require_jamb_institution_or_programme_choice(): void
    {
        $payload = $this->payload('250', [65, 70, 58, 57]);
        unset($payload['utme']['course_choice'], $payload['utme']['institution_choices']);

        $result = ApplicationFormSteps::validateUtme($this->request($payload), $payload, true);

        $this->assertSame('English Language', $result['utme']['subjects'][0]['subject']);
    }

    /**
     * @param  list<int|float>  $scores
     * @return array{utme: array<string, mixed>}
     */
    private function payload(string $aggregate, array $scores): array
    {
        $subjects = ['English Language', 'Mathematics', 'Physics', 'Chemistry'];

        return [
            'utme' => [
                'aggregate' => $aggregate,
                'exam_year' => '2025',
                'course_choice' => 'Computer Science',
                'institution_choices' => [
                    ['choice_order' => 1, 'institution_name' => 'Bells', 'programme_name' => 'Computer Science'],
                    ['choice_order' => 2, 'institution_name' => 'Unilag', 'programme_name' => 'Computer Science'],
                ],
                'subjects' => collect($scores)->values()->map(fn ($score, $index) => [
                    'subject' => $subjects[$index],
                    'score' => $score,
                ])->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(array $payload): Request
    {
        return Request::create('/', 'POST', ['payload' => $payload]);
    }
}
