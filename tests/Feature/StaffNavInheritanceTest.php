<?php

namespace Tests\Feature;

use App\Models\OfficeDepartment;
use App\Models\OfficeSubunit;
use App\Models\OfficeUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffNavInheritanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
    }

    public function test_unit_and_subunit_staff_inherit_department_portal_links(): void
    {
        $department = OfficeDepartment::query()->create([
            'name' => 'Registry',
            'code' => 'REG',
            'is_active' => true,
        ]);
        $department->syncNavKeys(['admissions-undergraduate', 'home']);

        $unit = OfficeUnit::query()->create([
            'office_department_id' => $department->id,
            'name' => 'Admissions Unit',
            'is_active' => true,
        ]);
        $unit->syncNavKeys(['candidate-data']);

        $subunit = OfficeSubunit::query()->create([
            'office_unit_id' => $unit->id,
            'name' => 'Desk A',
            'is_active' => true,
        ]);

        $unitStaff = $this->staffWithRole('unit@example.com', [
            'office_department_id' => $department->id,
            'office_unit_id' => $unit->id,
        ]);
        $subunitStaff = $this->staffWithRole('sub@example.com', [
            'office_department_id' => $department->id,
            'office_unit_id' => $unit->id,
            'office_subunit_id' => $subunit->id,
        ]);

        Sanctum::actingAs($unitStaff);
        $unitKeys = $this->getJson('/api/me')->assertOk()->json('nav_link_keys');
        $this->assertContains('admissions-undergraduate', $unitKeys);
        $this->assertContains('candidate-data', $unitKeys);

        Sanctum::actingAs($subunitStaff);
        $subunitKeys = $this->getJson('/api/me')->assertOk()->json('nav_link_keys');
        $this->assertContains('admissions-undergraduate', $subunitKeys);
        $this->assertContains('candidate-data', $subunitKeys);
    }

    public function test_office_structure_exposes_inherited_nav_keys(): void
    {
        $admin = $this->staffWithRole('admin@example.com', [], ['institution.manage']);
        $department = OfficeDepartment::query()->create([
            'name' => 'Bursary',
            'code' => 'BUR',
            'is_active' => true,
        ]);
        $department->syncNavKeys(['finance']);

        $unit = OfficeUnit::query()->create([
            'office_department_id' => $department->id,
            'name' => 'Fees',
            'is_active' => true,
        ]);
        $unit->syncNavKeys(['invoices']);

        OfficeSubunit::query()->create([
            'office_unit_id' => $unit->id,
            'name' => 'Collection',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $tree = $this->getJson('/api/office-structure')->assertOk()->json();
        $dept = collect($tree)->firstWhere('code', 'BUR');
        $this->assertNotNull($dept);
        $this->assertSame(['finance'], $dept['units'][0]['inherited_nav_keys']);
        $this->assertEqualsCanonicalizing(
            ['finance', 'invoices'],
            $dept['units'][0]['subunits'][0]['inherited_nav_keys'],
        );
    }

    /**
     * @param  array<string, mixed>  $placement
     * @param  list<string>  $permissions
     */
    private function staffWithRole(string $email, array $placement = [], array $permissions = ['admissions.view']): User
    {
        $role = Role::query()->create([
            'name' => 'Role '.$email,
            'slug' => 'role-'.substr(sha1($email), 0, 10),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id'),
        );

        $user = User::factory()->create(['email' => $email, 'status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'SN-'.strtoupper(substr(sha1($email), 0, 6)),
            ...$placement,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }
}
