<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\CandidateData;
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

    public function test_open_intakes_mark_jamb_and_candidate_list_requirements(): void
    {
        $intake = $this->openApplicationSession();
        CandidateData::query()->create([
            'rg_num' => '20261234AB',
            'academic_year' => '2025/2026',
            'rg_candname' => 'Ada Okoye',
        ]);

        $this->getJson('/api/intakes')
            ->assertOk()
            ->assertJsonPath('0.id', $intake->id)
            ->assertJsonPath('0.requires_jamb', true)
            ->assertJsonPath('0.candidate_list_required', true);
    }

    public function test_nin_preview_fails_when_no_application_session_is_accepting(): void
    {
        $this->demoPrembly();
        Http::fake();

        $this->postJson('/api/nin/preview', ['nin' => '12345678901'])
            ->assertStatus(422)
            ->assertJsonPath('code', Intake::CLOSED_SIGNUP_CODE)
            ->assertJsonPath('message', Intake::CLOSED_SIGNUP_MESSAGE);

        Http::assertNothingSent();
    }

    public function test_nin_preview_requires_an_accepting_intake_id(): void
    {
        $this->openApplicationSession();
        $this->demoPrembly();
        Http::fake();

        $this->postJson('/api/nin/preview', ['nin' => '12345678901'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['intake_id']);

        Http::assertNothingSent();
    }

    public function test_nin_preview_rejects_a_closed_intake_even_when_another_is_open(): void
    {
        $term = $this->openApplicationSession()->term;
        $closed = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'PG 2025',
            'entry_mode' => 'pg',
            'is_open' => false,
            'application_fee_amount' => 10000,
        ]);
        $this->demoPrembly();
        Http::fake();

        $this->postJson('/api/nin/preview', ['nin' => '12345678901', 'intake_id' => $closed->id])
            ->assertStatus(422)
            ->assertJsonPath('code', Intake::INTAKE_NOT_ACCEPTING_CODE);

        Http::assertNothingSent();
    }

    public function test_register_fails_when_no_application_session_is_accepting(): void
    {
        $this->demoPrembly();
        Http::fake();
        $this->ensureApplicantRole();

        $this->postJson('/api/register', $this->registerPayload())
            ->assertStatus(422)
            ->assertJsonPath('code', Intake::CLOSED_SIGNUP_CODE)
            ->assertJsonPath('message', Intake::CLOSED_SIGNUP_MESSAGE);

        Http::assertNothingSent();
        $this->assertSame(0, User::query()->count());
    }

    public function test_register_requires_intake_selection_when_sessions_are_open(): void
    {
        $this->openApplicationSession();
        $this->demoPrembly();
        Http::fake();
        $this->ensureApplicantRole();

        $this->postJson('/api/register', $this->registerPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['intake_id']);

        Http::assertNothingSent();
        $this->assertSame(0, User::query()->count());
    }

    public function test_register_requires_jamb_for_a_utme_session(): void
    {
        $intake = $this->openApplicationSession();
        $this->demoPrembly();
        Http::fake();
        $this->ensureApplicantRole();

        $this->postJson('/api/register', $this->registerPayload(['intake_id' => $intake->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['jamb_registration']);

        Http::assertNothingSent();
        $this->assertSame(0, User::query()->count());
    }

    public function test_nin_preview_succeeds_for_the_selected_accepting_intake(): void
    {
        $intake = $this->openApplicationSession();
        $this->demoPrembly();
        Http::fake();

        $this->postJson('/api/nin/preview', ['nin' => '12345678901', 'intake_id' => $intake->id])
            ->assertOk()
            ->assertJsonPath('first_name', 'Adaeze')
            ->assertJsonPath('last_name', 'Okoye');

        Http::assertNothingSent();
    }

    public function test_register_starts_application_for_the_selected_session(): void
    {
        $intake = $this->openApplicationSession();
        $this->ensureApplicantRole();
        $this->demoPrembly();
        Http::fake();

        $this->postJson('/api/register', $this->registerPayload([
            'intake_id' => $intake->id,
            'jamb_registration' => '20261234AB',
        ]))
            ->assertOk()
            ->assertJsonPath('message', 'Registration successful');

        $user = User::query()->where('email', 'adaeze.okoye@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('20261234AB', $user->jamb_registration);
        $application = Application::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($application);
        $this->assertSame($intake->id, $application->intake_id);
        $this->assertSame('utme', $application->entry_mode);
        $this->assertSame('awaiting_application_fee', $application->stage);
        Http::assertNothingSent();
    }

    public function test_register_allows_postgraduate_without_jamb(): void
    {
        $term = $this->openApplicationSession()->term;
        $pg = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'PG 2025',
            'entry_mode' => 'pg',
            'is_open' => true,
            'application_fee_amount' => 15000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        $this->ensureApplicantRole();
        $this->demoPrembly();
        Http::fake();

        $this->postJson('/api/register', $this->registerPayload(['intake_id' => $pg->id]))
            ->assertOk()
            ->assertJsonPath('message', 'Registration successful');

        $application = Application::query()->first();
        $this->assertSame('pg', $application->entry_mode);
        $this->assertSame($pg->id, $application->intake_id);
        $this->assertNull($application->jamb_registration);
    }

    public function test_register_rejects_jamb_missing_from_the_candidate_list(): void
    {
        $intake = $this->openApplicationSession();
        CandidateData::query()->create([
            'rg_num' => '20261234AB',
            'academic_year' => '2025/2026',
            'rg_candname' => 'Ada Okoye',
        ]);
        $this->ensureApplicantRole();
        $this->demoPrembly();
        Http::fake();

        $this->postJson('/api/register', $this->registerPayload([
            'intake_id' => $intake->id,
            'jamb_registration' => '20269999ZZ',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['jamb_registration']);

        Http::assertNothingSent();
        $this->assertSame(0, User::query()->count());
    }

    public function test_register_accepts_jamb_on_the_candidate_list(): void
    {
        $intake = $this->openApplicationSession();
        CandidateData::query()->create([
            'rg_num' => '20261234AB',
            'academic_year' => '2025/2026',
            'rg_candname' => 'Ada Okoye',
        ]);
        $this->ensureApplicantRole();
        $this->demoPrembly();
        Http::fake();

        $this->postJson('/api/register', $this->registerPayload([
            'intake_id' => $intake->id,
            'jamb_registration' => '20261234AB',
        ]))->assertOk();

        $this->assertSame('validated', Application::query()->value('jamb_status'));
    }

    public function test_applicant_session_badge_uses_year_not_intake_category(): void
    {
        $intake = $this->openApplicationSession();
        $intake->update(['name' => 'UTME']);
        $this->ensureApplicantRole();
        $this->demoPrembly();
        Http::fake();

        $this->postJson('/api/register', $this->registerPayload([
            'intake_id' => $intake->id,
            'jamb_registration' => '20261234AB',
        ]))->assertOk()
            ->assertJsonPath('current_session_kind', 'application')
            ->assertJsonPath('current_session', '2025/2026')
            ->assertJsonMissing(['current_session' => 'UTME']);
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

    private function demoPrembly(): void
    {
        config([
            'services.prembly.key' => '',
            'services.prembly.app_id' => '',
            'services.prembly.allow_demo' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'nin' => '12345678901',
            'email' => 'adaeze.okoye@gmail.com',
            'phone' => '08012345678',
            'password' => 'Secret1!x',
            'password_confirmation' => 'Secret1!x',
        ], $overrides);
    }
}
