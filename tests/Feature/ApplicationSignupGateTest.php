<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Intake;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApplicationSignupGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_info_reports_applications_closed_when_no_accepting_intake(): void
    {
        $this->getJson('/api/portal-info')
            ->assertOk()
            ->assertJsonPath('applications_open', false);
    }

    public function test_portal_info_reports_applications_open_when_an_intake_is_accepting(): void
    {
        $this->openApplicationSession();

        $this->getJson('/api/portal-info')
            ->assertOk()
            ->assertJsonPath('applications_open', true);
    }

    public function test_nin_preview_fails_when_no_application_session_is_accepting(): void
    {
        config([
            'services.prembly.key' => '',
            'services.prembly.app_id' => '',
            'services.prembly.allow_demo' => true,
        ]);
        Http::fake();

        $this->postJson('/api/nin/preview', ['nin' => '12345678901'])
            ->assertStatus(422)
            ->assertJsonPath('code', Intake::CLOSED_SIGNUP_CODE)
            ->assertJsonPath('message', Intake::CLOSED_SIGNUP_MESSAGE);

        Http::assertNothingSent();
    }

    public function test_register_fails_when_no_application_session_is_accepting(): void
    {
        config([
            'services.prembly.key' => '',
            'services.prembly.app_id' => '',
            'services.prembly.allow_demo' => true,
        ]);
        Http::fake();
        $this->ensureApplicantRole();

        $this->postJson('/api/register', $this->registerPayload())
            ->assertStatus(422)
            ->assertJsonPath('code', Intake::CLOSED_SIGNUP_CODE)
            ->assertJsonPath('message', Intake::CLOSED_SIGNUP_MESSAGE);

        Http::assertNothingSent();
        $this->assertSame(0, User::query()->count());
    }

    public function test_nin_preview_succeeds_when_an_application_session_is_accepting(): void
    {
        $this->openApplicationSession();
        config([
            'services.prembly.key' => '',
            'services.prembly.app_id' => '',
            'services.prembly.allow_demo' => true,
        ]);
        Http::fake();

        $this->postJson('/api/nin/preview', ['nin' => '12345678901'])
            ->assertOk()
            ->assertJsonPath('first_name', 'Adaeze')
            ->assertJsonPath('last_name', 'Okoye');

        Http::assertNothingSent();
    }

    public function test_register_succeeds_when_an_application_session_is_accepting(): void
    {
        $this->openApplicationSession();
        $this->ensureApplicantRole();
        config([
            'services.prembly.key' => '',
            'services.prembly.app_id' => '',
            'services.prembly.allow_demo' => true,
        ]);
        Http::fake();

        $this->postJson('/api/register', $this->registerPayload())
            ->assertOk()
            ->assertJsonPath('message', 'Registration successful');

        $this->assertTrue(User::query()->where('email', 'adaeze.okoye@gmail.com')->exists());
        Http::assertNothingSent();
    }

    private function openApplicationSession(): Intake
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
            'name' => 'UTME 2025',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
    }

    private function ensureApplicantRole(): void
    {
        Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
    }

    /**
     * @return array<string, string>
     */
    private function registerPayload(): array
    {
        return [
            'nin' => '12345678901',
            'email' => 'adaeze.okoye@gmail.com',
            'phone' => '08012345678',
            'password' => 'Secret1!x',
            'password_confirmation' => 'Secret1!x',
        ];
    }
}
