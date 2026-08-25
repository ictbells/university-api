<?php

namespace Tests\Feature;

use App\Models\NinVerification;
use App\Models\User;
use App\Services\PremblyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NinVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_info_reports_whether_prembly_is_live(): void
    {
        config([
            'services.prembly.key' => '',
            'services.prembly.app_id' => '',
        ]);

        $this->getJson('/api/portal-info')
            ->assertOk()
            ->assertJsonPath('nin_live', false)
            ->assertJsonPath('prembly_configured', false);

        config([
            'services.prembly.key' => 'test-key',
            'services.prembly.app_id' => 'test-app',
        ]);

        $this->getJson('/api/portal-info')
            ->assertOk()
            ->assertJsonPath('nin_live', true)
            ->assertJsonPath('prembly_configured', true);
    }

    public function test_preview_uses_demo_biodata_when_keys_are_missing_and_demo_is_allowed(): void
    {
        config([
            'services.prembly.key' => '',
            'services.prembly.app_id' => '',
            'services.prembly.allow_demo' => true,
        ]);
        Http::fake();

        $this->postJson('/api/nin/preview', ['nin' => '12345678901'])
            ->assertOk()
            ->assertJsonPath('first_name', 'Adaeze')
            ->assertJsonPath('last_name', 'Okoye')
            ->assertJsonPath('live', false);

        Http::assertNothingSent();
    }

    public function test_preview_fails_closed_when_live_keys_are_missing(): void
    {
        config([
            'services.prembly.key' => '',
            'services.prembly.app_id' => '',
            'services.prembly.allow_demo' => false,
        ]);
        Http::fake();

        $this->postJson('/api/nin/preview', ['nin' => '12345678901'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nin']);

        Http::assertNothingSent();
    }

    public function test_preview_posts_number_nin_to_prembly_and_maps_biodata(): void
    {
        config([
            'services.prembly.key' => 'test-key',
            'services.prembly.app_id' => 'test-app',
            'services.prembly.base' => 'https://api.prembly.com',
            'services.prembly.allow_demo' => false,
        ]);
        Http::fake([
            'https://api.prembly.com/identitypass/verification/vnin' => Http::response([
                'status' => true,
                'detail' => 'Verification Successful',
                'response_code' => '00',
                'verification' => ['reference' => 'ref-live-1'],
                'nin_data' => [
                    'firstname' => 'Chinedu',
                    'middlename' => 'Ike',
                    'surname' => 'Okafor',
                    'birthdate' => '12-01-2001',
                    'gender' => 'm',
                ],
            ], 200),
        ]);

        $this->postJson('/api/nin/preview', ['nin' => '12345678901'])
            ->assertOk()
            ->assertJsonPath('first_name', 'Chinedu')
            ->assertJsonPath('middle_name', 'Ike')
            ->assertJsonPath('last_name', 'Okafor')
            ->assertJsonPath('date_of_birth', '2001-01-12')
            ->assertJsonPath('gender', 'Male')
            ->assertJsonPath('live', true);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.prembly.com/identitypass/verification/vnin'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('app-id', 'test-app')
                && $request->data()['number_nin'] === '12345678901';
        });
    }

    public function test_preview_treats_prembly_status_false_as_failure(): void
    {
        config([
            'services.prembly.key' => 'test-key',
            'services.prembly.app_id' => 'test-app',
            'services.prembly.base' => 'https://api.prembly.com',
            'services.prembly.allow_demo' => false,
        ]);
        Http::fake([
            'https://api.prembly.com/identitypass/verification/vnin' => Http::response([
                'status' => false,
                'detail' => 'Invalid NIN supplied',
            ], 200),
        ]);

        $this->postJson('/api/nin/preview', ['nin' => '12345678901'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nin.0', 'Invalid NIN supplied');
    }

    public function test_verify_does_not_call_prembly_again_when_mapped_identity_is_passed(): void
    {
        config([
            'services.prembly.key' => 'test-key',
            'services.prembly.app_id' => 'test-app',
            'services.prembly.allow_demo' => false,
        ]);
        Http::fake();
        $user = User::factory()->create();

        $record = app(PremblyService::class)->verify($user, null, '12345678901', [
            'reference' => 'ref-live-1',
            'first_name' => 'Chinedu',
            'middle_name' => 'Ike',
            'last_name' => 'Okafor',
            'date_of_birth' => '2001-01-12',
            'gender' => 'Male',
            'raw' => ['firstname' => 'Chinedu'],
        ]);

        $this->assertSame('Chinedu', $record->mapped_fields['first_name']);
        $this->assertSame('ref-live-1', $record->prembly_reference);
        Http::assertNothingSent();
    }

    public function test_verify_replaces_a_demo_record_once_live_keys_exist(): void
    {
        config([
            'services.prembly.key' => '',
            'services.prembly.app_id' => '',
            'services.prembly.allow_demo' => true,
        ]);
        $user = User::factory()->create();
        NinVerification::query()->create([
            'user_id' => $user->id,
            'nin' => '12345678901',
            'prembly_reference' => 'DEMO-12345678901',
            'mapped_fields' => [
                'first_name' => 'Adaeze',
                'last_name' => 'Okoye',
                'raw' => ['demo' => true],
            ],
            'raw_snapshot' => ['demo' => true, 'nin' => '12345678901'],
            'verified_at' => now(),
        ]);

        config([
            'services.prembly.key' => 'test-key',
            'services.prembly.app_id' => 'test-app',
            'services.prembly.base' => 'https://api.prembly.com',
            'services.prembly.allow_demo' => false,
        ]);
        Http::fake([
            'https://api.prembly.com/identitypass/verification/vnin' => Http::response([
                'status' => true,
                'verification' => ['reference' => 'ref-live-2'],
                'data' => [
                    'nin_data' => [
                        'firstname' => 'Chinedu',
                        'surname' => 'Okafor',
                        'birthdate' => '2001-01-12',
                        'gender' => 'Male',
                    ],
                ],
            ], 200),
        ]);

        $record = app(PremblyService::class)->verify($user, null, '12345678901');

        $this->assertSame('Chinedu', $record->mapped_fields['first_name']);
        $this->assertSame('ref-live-2', $record->prembly_reference);
        $this->assertFalse($record->raw_snapshot['demo'] ?? false);
        Http::assertSentCount(1);
    }
}
