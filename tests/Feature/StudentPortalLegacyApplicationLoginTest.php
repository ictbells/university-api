<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Intake;
use App\Models\Role;
use App\Models\User;
use App\Support\StudentPortalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentPortalLegacyApplicationLoginTest extends TestCase
{
    use RefreshDatabase;

    private function jupebIntake(): Intake
    {
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);

        return Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'JUPEB 2025',
            'entry_mode' => 'jupeb',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
    }

    private function applicantRole(): Role
    {
        return Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'description' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
    }

    public function test_imported_legacy_application_number_can_sign_in(): void
    {
        $role = $this->applicantRole();
        $intake = $this->jupebIntake();
        $user = User::factory()->create([
            'name' => 'Jupeb Candidate',
            'password' => 'Aa1!l7s^1*(*?G',
            'status' => 'active',
        ]);
        $user->roles()->sync([$role->id]);
        Application::query()->create([
            'application_number' => 'BELLS-APP-NUM-JUPEB-0119',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'jupeb',
            'stage' => 'form_in_progress',
        ]);

        $this->assertSame(
            $user->id,
            StudentPortalAuth::resolveUser('BELLS-APP-NUM-JUPEB-0119')?->id,
        );

        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => 'BELLS-APP-NUM-JUPEB-0119',
            'password' => 'Aa1!l7s^1*(*?G',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_generated_app_slash_numbers_still_sign_in(): void
    {
        $role = $this->applicantRole();
        $intake = $this->jupebIntake();
        $user = User::factory()->create([
            'password' => 'Secret1!pass',
            'status' => 'active',
        ]);
        $user->roles()->sync([$role->id]);
        Application::query()->create([
            'application_number' => 'APP/2026/01003',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'jupeb',
            'stage' => 'form_in_progress',
        ]);

        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => 'APP/2026/01003',
            'password' => 'Secret1!pass',
        ])->assertOk();
    }
}
