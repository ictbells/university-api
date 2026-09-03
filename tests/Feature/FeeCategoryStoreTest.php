<?php

namespace Tests\Feature;

use App\Models\FeeCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeeCategoryStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_category_that_already_exists_suffixes_the_code(): void
    {
        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/fee-categories', [
            'name' => 'BUSA Fee',
            'is_schedule' => true,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('code', 'busa_fee');

        $this->postJson('/api/fee-categories', [
            'name' => 'BUSA Fee',
            'is_schedule' => true,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('code', 'busa_fee_2');
    }

    public function test_recreating_a_soft_deleted_category_restores_the_same_code(): void
    {
        Sanctum::actingAs($this->staffUser());

        $id = $this->postJson('/api/fee-categories', [
            'name' => 'BUSA Fee',
            'is_schedule' => true,
            'is_active' => true,
        ])->assertCreated()
            ->json('id');

        $this->deleteJson('/api/fee-categories/'.$id)->assertNoContent();
        $this->assertSoftDeleted('fee_categories', ['id' => $id, 'code' => 'busa_fee']);

        $this->postJson('/api/fee-categories', [
            'name' => 'BUSA Fee',
            'description' => 'Restored category',
            'is_schedule' => true,
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('code', 'busa_fee')
            ->assertJsonPath('description', 'Restored category');

        $this->assertDatabaseHas('fee_categories', [
            'id' => $id,
            'code' => 'busa_fee',
            'deleted_at' => null,
        ]);
        $this->assertSame(1, FeeCategory::withTrashed()->where('code', 'busa_fee')->count());
    }

    private function staffUser(): User
    {
        Permission::query()->updateOrCreate(
            ['key' => 'finance.invoices.manage'],
            ['module' => 'finance', 'label' => 'Manage invoices'],
        );

        $role = Role::query()->create([
            'name' => 'Bursary tester',
            'slug' => 'bursary-tester-'.Str::lower(Str::random(8)),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->where('key', 'finance.invoices.manage')->pluck('id'),
        );

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
