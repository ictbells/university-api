<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamClearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_exam_clearance_json_and_printable_html(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Student::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'matric_number' => 'BUT/2026/E/0001',
            'status' => 'active',
            'current_level' => 100,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/exam-clearance')
            ->assertOk()
            ->assertJsonPath('student.matric_number', 'BUT/2026/E/0001')
            ->assertJsonPath('student.name', 'Ada Lovelace')
            ->assertJsonStructure(['cleared', 'status', 'checks', 'student']);

        $this->get('/api/exam-clearance?format=html')
            ->assertOk()
            ->assertSee('Exam clearance', false)
            ->assertSee('BUT/2026/E/0001', false)
            ->assertSee('ADA LOVELACE', false);
    }
}
