<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfferAcceptancePortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_flags_unpaid_acceptance_when_offer_is_issued_without_invoice(): void
    {
        $user = $this->applicantWithOffer('offer_issued');
        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('unpaid_acceptance_fee', true)
            ->assertJsonPath('lifecycle_stage', 'offer_issued');
    }

    public function test_me_flags_unpaid_acceptance_when_invoice_is_outstanding(): void
    {
        $user = $this->applicantWithOffer('awaiting_acceptance_fee');
        $invoice = Invoice::query()->create([
            'number' => 'ACC-001',
            'user_id' => $user->id,
            'application_id' => $user->latestApplication->id,
            'category' => 'acceptance_fee',
            'amount' => 25000,
            'balance' => 25000,
            'status' => 'unpaid',
        ]);
        $user->latestApplication->update(['acceptance_fee_invoice_id' => $invoice->id]);

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('unpaid_acceptance_fee', true);
    }

    public function test_opening_the_file_prefers_catalog_amount_over_intake_default(): void
    {
        $user = $this->applicantWithOffer('offer_issued', 7000);
        \App\Models\FeeItem::query()->create([
            'name' => 'Acceptance',
            'category' => 'acceptance_fee',
            'amount' => 8000,
            'is_active' => true,
            'wallet_allowed' => false,
        ]);
        $application = $user->latestApplication;
        $this->assertNull($application->acceptance_fee_invoice_id);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/applications/{$application->id}")->assertOk();
        $this->assertSame('unpaid', $response->json('acceptance_fee_invoice.status'));
        $this->assertEquals(8000, (float) $response->json('acceptance_fee_invoice.amount'));
        $this->assertNotNull($application->fresh()->acceptance_fee_invoice_id);
        $this->assertSame('awaiting_acceptance_fee', $application->fresh()->stage);
    }

    public function test_opening_the_file_uses_intake_default_when_catalog_is_missing(): void
    {
        $user = $this->applicantWithOffer('offer_issued', 7000);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/applications/{$user->latestApplication->id}")->assertOk();
        $this->assertEquals(7000, (float) $response->json('acceptance_fee_invoice.amount'));
    }

    public function test_me_does_not_flag_acceptance_after_fee_is_paid(): void
    {
        $user = $this->applicantWithOffer('awaiting_acceptance_fee');
        $invoice = Invoice::query()->create([
            'number' => 'ACC-002',
            'user_id' => $user->id,
            'application_id' => $user->latestApplication->id,
            'category' => 'acceptance_fee',
            'amount' => 25000,
            'balance' => 0,
            'status' => 'paid',
        ]);
        $user->latestApplication->update(['acceptance_fee_invoice_id' => $invoice->id]);

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('unpaid_acceptance_fee', false);
    }

    private function applicantWithOffer(string $stage, float $intakeAmount = 7000): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        $session = AcademicSession::query()->create(['label' => '2026/2027']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2026/2027',
            'is_current' => true,
        ]);
        $intake = Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2026',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'acceptance_fee_amount' => $intakeAmount,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
        Application::query()->create([
            'application_number' => 'APP/2026/00999',
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => 'utme',
            'stage' => $stage,
            'offer_reference' => 'OFF/2026/0001',
        ]);

        return $user->fresh(['latestApplication']);
    }
}
