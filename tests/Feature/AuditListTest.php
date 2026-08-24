<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditListTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_audit_view(): void
    {
        Sanctum::actingAs($this->staffUser([]));

        $this->getJson('/api/audit-logs')
            ->assertForbidden()
            ->assertJson(['message' => 'This action is not authorized.']);
    }

    public function test_index_paginates_and_returns_facets(): void
    {
        Sanctum::actingAs($this->staffUser(['audit.view']));

        foreach (range(1, 30) as $i) {
            $this->log([
                'action' => $i % 2 === 0 ? 'user.updated' : 'invoice.disabled',
                'module' => $i % 2 === 0 ? 'users' : 'fees',
                'summary' => 'Event '.$i,
            ]);
        }

        $response = $this->getJson('/api/audit-logs?per_page=10')->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(30, $response->json('total'));
        $this->assertSame(3, $response->json('last_page'));
        $this->assertContains('users', $response->json('facets.modules'));
        $this->assertContains('fees', $response->json('facets.modules'));
        $this->assertSame(1, $response->json('summary.actors'));
        $this->assertSame(2, $response->json('summary.modules'));
    }

    public function test_search_and_filters_narrow_results(): void
    {
        Sanctum::actingAs($this->staffUser(['audit.view']));

        $this->log([
            'actor_email' => 'alice@example.com',
            'actor_name' => 'Alice Registrar',
            'action' => 'user.updated',
            'module' => 'users',
            'summary' => 'Changed a staff role',
            'occurred_at' => now()->subDays(3),
        ]);
        $this->log([
            'actor_email' => 'bob@example.com',
            'action' => 'invoice.disabled',
            'module' => 'fees',
            'summary' => 'Disabled unpaid invoice',
            'request_id' => 'req-secret-99',
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/audit-logs?search=alice')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.actor_email', 'alice@example.com');

        $this->getJson('/api/audit-logs?module=fees')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.action', 'invoice.disabled');

        $this->getJson('/api/audit-logs?search=req-secret-99')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.request_id', 'req-secret-99');

        $this->getJson('/api/audit-logs?from='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.actor_email', 'bob@example.com');
    }

    public function test_export_omits_before_and_after_state(): void
    {
        Sanctum::actingAs($this->staffUser(['audit.view']));

        $this->log([
            'actor_email' => 'alice@example.com',
            'summary' => 'Visible summary line',
            'before_state' => ['secret' => 'NIN_SHOULD_NOT_EXPORT_999'],
            'after_state' => ['secret' => 'NIN_SHOULD_NOT_EXPORT_999'],
        ]);

        $response = $this->get('/api/audit-logs/export?format=excel&search=alice')
            ->assertOk();

        $this->assertStringContainsString(
            'attachment; filename=',
            (string) $response->headers->get('content-disposition'),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'audit');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $strings = (string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertStringContainsString('Visible summary line', $strings);
        $this->assertStringContainsString('alice@example.com', $strings);
        $this->assertStringNotContainsString('NIN_SHOULD_NOT_EXPORT_999', $strings);
        $this->assertStringNotContainsString('before_state', $strings);
    }

    public function test_export_requires_audit_view(): void
    {
        Sanctum::actingAs($this->staffUser([]));

        $this->getJson('/api/audit-logs/export?format=pdf')
            ->assertForbidden();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        foreach ($permissions as $key) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['module' => 'audit', 'label' => $key],
            );
        }

        $role = Role::query()->create([
            'name' => 'Audit tester',
            'slug' => 'audit-tester-'.Str::lower(Str::random(8)),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
        );

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function log(array $overrides = []): AuditLog
    {
        return AuditLog::query()->create(array_merge([
            'actor_type' => 'user',
            'actor_email' => 'staff@example.com',
            'actor_name' => 'Staff User',
            'action' => 'user.updated',
            'summary' => 'Updated a user',
            'occurred_at' => now(),
            'module' => 'users',
            'request_id' => (string) Str::uuid(),
            'row_hash' => hash('sha256', (string) Str::uuid()),
        ], $overrides));
    }
}
