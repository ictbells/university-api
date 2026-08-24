<?php

namespace Tests\Feature;

use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavedReport;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\Reports\ReportDatasetCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
    }

    public function test_reports_require_view_permission(): void
    {
        $user = $this->staffUser(['students.view_any'], ['home', 'reports']);

        Sanctum::actingAs($user);
        $this->getJson('/api/reports/datasets')
            ->assertForbidden()
            ->assertJson(['message' => 'This action is not authorized.']);
    }

    public function test_reports_require_office_portal_link(): void
    {
        $user = $this->staffUser(['reports.view', 'admissions.view'], ['home']);

        Sanctum::actingAs($user);
        $this->getJson('/api/reports/datasets')
            ->assertForbidden()
            ->assertJson([
                'message' => 'This module is not enabled for your office portal link.',
                'access_reason' => 'missing_portal_link',
            ]);
    }

    public function test_datasets_are_filtered_by_domain_permission(): void
    {
        $user = $this->staffUser(['reports.view', 'admissions.view'], ['home', 'reports']);

        Sanctum::actingAs($user);
        $keys = collect($this->getJson('/api/reports/datasets')->assertOk()->json('data'))->pluck('key');

        $this->assertTrue($keys->contains('applications'));
        $this->assertFalse($keys->contains('invoices'));
        $this->assertFalse($keys->contains('clinic_visits'));
        $this->assertFalse($keys->contains('audit_logs'));
    }

    public function test_run_is_forbidden_without_dataset_permission(): void
    {
        $user = $this->staffUser(['reports.view', 'admissions.view'], ['home', 'reports']);

        Sanctum::actingAs($user);
        $this->postJson('/api/reports/run', [
            'dataset' => 'invoices',
            'columns' => ['number', 'amount'],
        ])->assertForbidden()->assertJsonPath('access_reason', 'missing_permission');
    }

    public function test_run_succeeds_when_all_three_gates_pass(): void
    {
        $user = $this->staffUser(['reports.view', 'admissions.view'], ['home', 'reports']);

        Sanctum::actingAs($user);
        $this->postJson('/api/reports/run', [
            'dataset' => 'applications',
            'columns' => ['application_number', 'stage'],
        ])->assertOk()->assertJsonPath('dataset', 'applications');
    }

    public function test_saving_requires_manage_permission(): void
    {
        $viewer = $this->staffUser(['reports.view', 'admissions.view'], ['home', 'reports']);
        Sanctum::actingAs($viewer);
        $this->postJson('/api/reports/saved', [
            'name' => 'Apps by stage',
            'dataset_key' => 'applications',
            'visibility' => 'private',
            'definition' => [
                'dataset' => 'applications',
                'columns' => ['stage'],
                'group_by' => ['stage'],
            ],
        ])->assertForbidden();

        $manager = $this->staffUser(['reports.view', 'reports.manage', 'admissions.view'], ['home', 'reports']);
        Sanctum::actingAs($manager);
        $this->postJson('/api/reports/saved', [
            'name' => 'Apps by stage',
            'dataset_key' => 'applications',
            'visibility' => 'shared',
            'definition' => [
                'dataset' => 'applications',
                'columns' => ['stage'],
                'group_by' => ['stage'],
            ],
        ])->assertCreated();
    }

    public function test_shared_report_is_hidden_without_dataset_permission(): void
    {
        $owner = $this->staffUser(['reports.view', 'reports.manage', 'finance.invoices.manage'], ['home', 'reports']);
        $report = SavedReport::query()->create([
            'name' => 'Invoice totals',
            'dataset_key' => 'invoices',
            'visibility' => 'shared',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'definition' => ['dataset' => 'invoices', 'columns' => ['number'], 'filters' => [], 'group_by' => [], 'aggregations' => [], 'sorts' => []],
        ]);

        $viewer = $this->staffUser(['reports.view', 'admissions.view'], ['home', 'reports']);
        Sanctum::actingAs($viewer);
        $ids = collect($this->getJson('/api/reports/saved')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($report->id));

        $this->getJson('/api/reports/saved/'.$report->id)
            ->assertForbidden()
            ->assertJsonPath('access_reason', 'missing_permission');
    }

    public function test_student_nin_and_clinic_internal_notes_are_not_selectable(): void
    {
        $students = ReportDatasetCatalog::get('students');
        $clinic = ReportDatasetCatalog::get('clinic_visits');

        $this->assertNotNull($students);
        $this->assertNotNull($clinic);
        $this->assertNull($students->column('nin'));
        $this->assertNull($clinic->column('notes_internal'));
        $this->assertNull($clinic->column('notes'));

        $user = $this->staffUser(['reports.view', 'students.view_any'], ['home', 'reports']);
        $studentUser = User::factory()->create();
        Student::query()->create([
            'user_id' => $studentUser->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'nin' => '12345678901',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/reports/run', [
            'dataset' => 'students',
            'columns' => ['nin'],
        ])->assertStatus(422);

        $payload = $this->postJson('/api/reports/run', [
            'dataset' => 'students',
            'columns' => ['first_name', 'last_name'],
        ])->assertOk()->json();

        $this->assertStringNotContainsString('12345678901', json_encode($payload));
    }

    public function test_super_admin_is_unrestricted_for_portal_nav(): void
    {
        $role = Role::query()->create([
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'is_system' => true,
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'SA-1',
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/reports/datasets')->assertOk();
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $navKeys
     */
    private function staffUser(array $permissions, array $navKeys): User
    {
        $role = Role::query()->create([
            'name' => 'Test '.implode('-', $permissions),
            'slug' => 'test-'.substr(sha1(implode(',', $permissions).implode(',', $navKeys).uniqid()), 0, 12),
            'is_system' => false,
            'is_active' => true,
        ]);
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $role->permissions()->sync($ids);

        $office = OfficeDepartment::query()->create([
            'name' => 'Test office '.$role->slug,
            'code' => substr($role->slug, 0, 20),
            'is_active' => true,
        ]);
        $office->syncNavKeys($navKeys);

        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-'.strtoupper(substr($role->slug, -8)),
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }
}
