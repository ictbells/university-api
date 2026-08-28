<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_read_the_seeded_published_guide(): void
    {
        $this->getJson('/api/admission-guide')
            ->assertOk()
            ->assertJsonPath('guide.title', 'Undergraduate Admission Guide — 2026/2027')
            ->assertJsonFragment(['heading' => 'How to apply on this portal']);

        $html = $this->get('/api/admission-guide/print')->assertOk()->getContent();
        $this->assertStringContainsString('Undergraduate Admission Guide', $html);
        $this->assertStringContainsString('Who can apply', $html);
        $this->assertStringContainsString('Documents you will need', $html);
    }

    public function test_unpublished_guide_is_hidden_from_the_student_portal(): void
    {
        Sanctum::actingAs($this->staffUser(['admissions.guide']));
        $this->postJson('/api/staff/admission-guide/unpublish')->assertOk();

        $this->getJson('/api/admission-guide')
            ->assertOk()
            ->assertJson(['guide' => null]);

        $this->getJson('/api/admission-guide/print')->assertNotFound();
    }

    public function test_staff_can_update_and_publish_the_guide(): void
    {
        Sanctum::actingAs($this->staffUser(['admissions.guide']));

        $this->putJson('/api/staff/admission-guide', [
            'title' => 'Postgraduate Admission Guide',
            'intro' => 'Use this guide before you apply.',
            'sections' => [
                ['heading' => 'Research degrees', 'body' => 'Attach a proposal summary.'],
            ],
        ])->assertOk()->assertJsonPath('title', 'Postgraduate Admission Guide');

        $this->postJson('/api/staff/admission-guide/publish')->assertOk();

        $this->getJson('/api/admission-guide')
            ->assertOk()
            ->assertJsonPath('guide.title', 'Postgraduate Admission Guide')
            ->assertJsonPath('guide.sections.0.heading', 'Research degrees');
    }

    public function test_staff_without_permission_cannot_edit_the_guide(): void
    {
        Sanctum::actingAs($this->staffUser(['admissions.view']));

        $this->getJson('/api/staff/admission-guide')->assertForbidden();
        $this->putJson('/api/staff/admission-guide', [
            'title' => 'Should not save',
            'sections' => [['heading' => 'A', 'body' => 'B']],
        ])->assertForbidden();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        foreach ($permissions as $key) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['module' => 'admissions', 'label' => $key],
            );
        }

        $role = Role::query()->create([
            'name' => 'Guide tester',
            'slug' => 'guide-tester-'.Str::lower(Str::random(8)),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
        );

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
