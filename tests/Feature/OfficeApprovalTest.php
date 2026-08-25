<?php

namespace Tests\Feature;

use App\Models\OfficeApprovalRequest;
use App\Models\OfficeDepartment;
use App\Models\OfficeSubunit;
use App\Models\OfficeUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\OfficeApprovalService;
use App\Services\OfficeNavOwnerResolver;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfficeApprovalTest extends TestCase
{
    use RefreshDatabase;

    private OfficeDepartment $department;

    private OfficeUnit $unit;

    private OfficeSubunit $subunit;

    private User $hod;

    private User $unitHead;

    private User $subunitStaff;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        $this->department = OfficeDepartment::query()->create([
            'name' => 'Student Affairs',
            'code' => 'SA',
            'is_active' => true,
        ]);
        $this->department->syncNavKeys(['hostel']);

        $this->unit = OfficeUnit::query()->create([
            'office_department_id' => $this->department->id,
            'name' => 'Hostel Unit',
            'is_active' => true,
        ]);
        $this->subunit = OfficeSubunit::query()->create([
            'office_unit_id' => $this->unit->id,
            'name' => 'Allocation Desk',
            'is_active' => true,
        ]);

        $this->hod = $this->staffInOffice('hod@example.com', [
            'office_department_id' => $this->department->id,
        ]);
        $this->unitHead = $this->staffInOffice('unithead@example.com', [
            'office_department_id' => $this->department->id,
            'office_unit_id' => $this->unit->id,
        ]);
        $this->subunitStaff = $this->staffInOffice('desk@example.com', [
            'office_department_id' => $this->department->id,
            'office_unit_id' => $this->unit->id,
            'office_subunit_id' => $this->subunit->id,
        ]);

        $this->department->update(['head_staff_id' => $this->hod->staff->id]);
        $this->unit->update(['head_staff_id' => $this->unitHead->staff->id]);
    }

    public function test_subunit_staff_are_blocked_when_the_parent_unit_has_no_head(): void
    {
        $this->unit->update(['head_staff_id' => null]);
        Sanctum::actingAs($this->subunitStaff);

        try {
            $this->approvals()->submitOrExecute('test.echo', null, ['ping' => 1], 'Test echo');
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('unit head', strtolower($e->getMessage()));
        }

        $this->assertSame(0, OfficeApprovalRequest::query()->count());
    }

    public function test_subunit_request_goes_to_unit_head_then_hod_then_executes(): void
    {
        Sanctum::actingAs($this->subunitStaff);
        $pending = $this->approvals()->submitOrExecute('test.echo', null, ['ping' => 1], 'Test echo');

        $this->assertSame(202, $pending->getStatusCode());
        $payload = $pending->getData(true);
        $this->assertSame('pending_approval', $payload['status']);
        $this->assertSame(OfficeApprovalRequest::PENDING_UNIT_HEAD, $payload['approval_request']['status']);

        $request = OfficeApprovalRequest::query()->first();
        $this->assertNotNull($request);

        Sanctum::actingAs($this->unitHead);
        $this->postJson("/api/office-approvals/{$request->id}/approve", ['comment' => 'Unit ok'])
            ->assertOk()
            ->assertJsonPath('status', OfficeApprovalRequest::PENDING_HOD);

        Sanctum::actingAs($this->hod);
        $this->postJson("/api/office-approvals/{$request->id}/approve", ['comment' => 'HOD ok'])
            ->assertOk();

        $this->assertSame(OfficeApprovalRequest::APPROVED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->executed_at);
    }

    public function test_super_admin_executes_immediately(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $role = Role::query()->create([
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'is_system' => true,
            'is_active' => true,
        ]);
        $admin->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $admin->id,
            'staff_number' => 'SA-1',
            'office_department_id' => $this->department->id,
        ]);

        Sanctum::actingAs($admin->fresh(['roles', 'staff']));
        $result = $this->approvals()->submitOrExecute('test.echo', null, ['ping' => 1], 'Test echo');

        $this->assertIsArray($result);
        $this->assertSame(1, $result['ping']);
        $this->assertSame(0, OfficeApprovalRequest::query()->count());
    }

    public function test_duplicate_open_request_is_rejected(): void
    {
        Sanctum::actingAs($this->subunitStaff);
        $this->approvals()->submitOrExecute('test.echo', null, ['ping' => 1], 'Test echo');

        try {
            $this->approvals()->submitOrExecute('test.echo', null, ['ping' => 2], 'Test echo again');
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already waiting', strtolower($e->getMessage()));
        }
    }

    public function test_nav_key_cannot_belong_to_two_departments(): void
    {
        $other = OfficeDepartment::query()->create([
            'name' => 'Registry',
            'code' => 'REG',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(OfficeNavOwnerResolver::class)->assertKeysUniqueToDepartment($other, ['hostel']);
    }

    public function test_mutations_execute_when_nav_link_does_not_require_approval(): void
    {
        $this->department->syncNavLinks([
            [
                'key' => 'hostel',
                'require_create' => false,
                'require_update' => false,
                'require_delete' => false,
                'approval_chain' => 'both',
            ],
        ]);

        Sanctum::actingAs($this->subunitStaff);
        $result = $this->approvals()->submitOrExecute('test.echo', null, ['ping' => 9], 'Test echo');

        $this->assertIsArray($result);
        $this->assertSame(9, $result['ping']);
        $this->assertSame(0, OfficeApprovalRequest::query()->count());
    }

    public function test_unit_head_inbox_lists_pending_unit_requests(): void
    {
        Sanctum::actingAs($this->subunitStaff);
        $this->approvals()->submitOrExecute('test.echo', null, ['ping' => 1], 'Test echo');

        Sanctum::actingAs($this->unitHead);
        $this->getJson('/api/office-approvals?scope=review')
            ->assertOk()
            ->assertJsonPath('data.0.status', OfficeApprovalRequest::PENDING_UNIT_HEAD)
            ->assertJsonPath('data.0.can_review', true);

        // HOD seniority: also sees pending unit-head items
        Sanctum::actingAs($this->hod);
        $this->getJson('/api/office-approvals?scope=review')
            ->assertOk()
            ->assertJsonPath('data.0.status', OfficeApprovalRequest::PENDING_UNIT_HEAD)
            ->assertJsonPath('data.0.can_review', true);
    }

    public function test_hod_can_approve_before_unit_head_by_seniority(): void
    {
        Sanctum::actingAs($this->subunitStaff);
        $this->approvals()->submitOrExecute('test.echo', null, ['ping' => 1], 'Test echo');
        $request = OfficeApprovalRequest::query()->firstOrFail();
        $this->assertSame(OfficeApprovalRequest::PENDING_UNIT_HEAD, $request->status);

        Sanctum::actingAs($this->hod);
        $this->postJson("/api/office-approvals/{$request->id}/approve", ['comment' => 'HOD override'])
            ->assertOk();

        $request->refresh();
        $this->assertSame(OfficeApprovalRequest::APPROVED, $request->status);
        $this->assertNotNull($request->executed_at);
        $this->assertNotNull($request->hod_reviewed_at);
    }

    public function test_require_delete_false_executes_delete_immediately(): void
    {
        $this->department->syncNavLinks([[
            'key' => 'hostel',
            'require_create' => true,
            'require_update' => true,
            'require_delete' => false,
            'approval_chain' => 'both',
        ]]);

        Sanctum::actingAs($this->subunitStaff);
        $result = $this->approvals()->submitOrExecute('test.echo_delete', null, ['ping' => 9], 'Delete echo');
        $this->assertIsArray($result);
        $this->assertSame(9, $result['ping']);
        $this->assertSame(0, OfficeApprovalRequest::query()->count());
    }

    public function test_approval_chain_unit_head_only_executes_after_unit_approve(): void
    {
        $this->department->syncNavLinks([[
            'key' => 'hostel',
            'require_create' => true,
            'require_update' => true,
            'require_delete' => true,
            'approval_chain' => 'unit_head',
        ]]);

        Sanctum::actingAs($this->subunitStaff);
        $pending = $this->approvals()->submitOrExecute('test.echo', null, ['ping' => 1], 'Test echo');
        $this->assertSame(202, $pending->getStatusCode());

        $request = OfficeApprovalRequest::query()->firstOrFail();
        Sanctum::actingAs($this->unitHead);
        $this->postJson("/api/office-approvals/{$request->id}/approve")
            ->assertOk();

        $request->refresh();
        $this->assertSame(OfficeApprovalRequest::APPROVED, $request->status);
        $this->assertNotNull($request->executed_at);
        $this->assertNull($request->hod_reviewed_at);
    }

    public function test_approval_chain_department_head_only_skips_unit_head(): void
    {
        $this->department->syncNavLinks([[
            'key' => 'hostel',
            'require_create' => true,
            'require_update' => true,
            'require_delete' => true,
            'approval_chain' => 'department_head',
        ]]);

        Sanctum::actingAs($this->subunitStaff);
        $pending = $this->approvals()->submitOrExecute('test.echo', null, ['ping' => 1], 'Test echo');
        $payload = $pending->getData(true);
        $this->assertSame(OfficeApprovalRequest::PENDING_HOD, $payload['approval_request']['status']);
    }

    private function approvals(): OfficeApprovalService
    {
        return app(OfficeApprovalService::class);
    }

    /**
     * @param  array<string, int>  $placement
     */
    private function staffInOffice(string $email, array $placement): User
    {
        $user = User::factory()->create(['email' => $email]);
        Staff::query()->create(array_merge([
            'user_id' => $user->id,
            'staff_number' => 'ST-'.substr(sha1($email), 0, 8),
        ], $placement));

        return $user->fresh('staff');
    }
}
