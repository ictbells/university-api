<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicantCollegeCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_list_colleges(): void
    {
        $this->getJson('/api/colleges')->assertUnauthorized();
    }

    public function test_applicant_sees_staff_created_college_before_programmes_exist(): void
    {
        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create([
            'campus_id' => $campus->id,
            'name' => 'College of Law',
            'code' => 'LAW',
        ]);
        Department::query()->create([
            'faculty_id' => $faculty->id,
            'name' => 'Private Law',
            'code' => 'PLW',
        ]);

        $role = Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/colleges')
            ->assertOk()
            ->assertJsonFragment(['name' => 'College of Law'])
            ->assertJsonFragment(['name' => 'Private Law']);

        $this->getJson('/api/programs?entry_mode=utme')
            ->assertOk()
            ->assertExactJson([]);
    }
}
