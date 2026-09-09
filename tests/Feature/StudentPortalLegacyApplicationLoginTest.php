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

    public function test_imported_transfer_legacy_application_number_with_slashes_can_sign_in(): void
    {
        $role = $this->applicantRole();
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
        $intake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'Transfer 2026',
            'entry_mode' => 'transfer',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        $user = User::factory()->create([
            'name' => 'Transfer Candidate',
            'password' => 'Aa1!l7s^1*(*?G',
            'status' => 'active',
        ]);
        $user->roles()->sync([$role->id]);
        Application::query()->create([
            'application_number' => 'BELLSTECH/2026/T/00002',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'transfer',
            'stage' => 'form_in_progress',
        ]);

        $this->assertSame(
            $user->id,
            StudentPortalAuth::resolveUser('BELLSTECH/2026/T/00002')?->id,
        );

        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => 'BELLSTECH/2026/T/00002',
            'password' => 'Aa1!l7s^1*(*?G',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_matriculated_must_use_matric_not_application_number(): void
    {
        $role = $this->applicantRole();
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
        $intake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'Transfer 2026',
            'entry_mode' => 'transfer',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        $user = User::factory()->create([
            'password' => 'Aa1!l7s^1*(*?G',
            'status' => 'active',
        ]);
        $user->roles()->sync([$role->id]);
        $application = Application::query()->create([
            'application_number' => 'BELLSTECH/2026/T/00002',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'transfer',
            'stage' => 'matriculated',
        ]);
        \App\Models\Student::query()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'matric_number' => '2026/000150',
            'student_number' => '2026/000150',
            'current_level' => 200,
            'status' => 'active',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        StudentPortalAuth::resolveUser('BELLSTECH/2026/T/00002');
    }

    public function test_matriculated_can_sign_in_with_year_serial_matric(): void
    {
        $role = $this->applicantRole();
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);
        $intake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2026',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        $user = User::factory()->create([
            'password' => 'Aa1!l7s^1*(*?G',
            'status' => 'active',
        ]);
        $user->roles()->sync([$role->id]);
        $application = Application::query()->create([
            'application_number' => 'APP/2026/01099',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'utme',
            'stage' => 'matriculated',
        ]);
        \App\Models\Student::query()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'matric_number' => '2026/000151',
            'student_number' => '2026/000151',
            'current_level' => 100,
            'status' => 'active',
        ]);

        $this->assertSame(
            $user->id,
            StudentPortalAuth::resolveUser('2026/000151')?->id,
        );

        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => '2026/000151',
            'password' => 'Aa1!l7s^1*(*?G',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/login', [
            'portal' => 'student',
            'login' => 'APP/2026/01099',
            'password' => 'Aa1!l7s^1*(*?G',
        ])->assertStatus(422)
            ->assertJsonFragment(['login' => [
                'You have been matriculated. Please sign in with your matric number instead of your application number.',
            ]]);
    }
}
