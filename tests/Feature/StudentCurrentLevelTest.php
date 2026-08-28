<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentCurrentLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_undergraduate_levels_above_255_can_be_stored(): void
    {
        $student = Student::query()->create([
            'user_id' => User::factory()->create()->id,
            'first_name' => 'Ada',
            'last_name' => 'Okoye',
            'current_level' => 100,
            'study_level' => 'undergraduate',
            'status' => 'active',
        ]);

        foreach ([200, 300, 400, 500] as $level) {
            $student->update(['current_level' => $level]);
            $this->assertSame($level, (int) $student->fresh()->current_level);
        }
    }
}
