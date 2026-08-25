<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\WorkflowCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        WorkflowCatalog::seed();
        $this->call(GradingScaleSeeder::class);
        $this->call(StateOfOriginSeeder::class);
        $allIds = Permission::query()->pluck('id');

        $roles = [
            'super-admin' => ['Super Admin', true, $allIds],
            'registrar' => ['Registrar', true, Permission::query()->whereIn('module', ['sis', 'academic', 'admissions', 'registrations', 'reports', 'institution'])->pluck('id')],
            'admissions' => ['Admissions', true, Permission::query()
                ->where('module', 'admissions')
                ->orWhereIn('key', [
                    'students.view_any',
                    'pg.view',
                    'academic.programmes.manage',
                    'academic.intakes.manage',
                    'academic.olevel.manage',
                    'admissions.import',
                    'registrations.view',
                ])
                ->pluck('id')],
            'finance' => ['Finance', true, Permission::query()->whereIn('module', ['fees', 'payments', 'wallet'])->pluck('id')],
            'medical' => ['Medical', true, Permission::query()->where('module', 'medical')->pluck('id')],
            'faculty' => ['Faculty', true, Permission::query()->whereIn('key', ['students.view_any'])->pluck('id')],
            'pg-coordinator' => ['PG Coordinator', true, Permission::query()->where('module', 'postgraduate')->orWhereIn('key', [
                'admissions.view',
                'admissions.pg.screen',
                'admissions.pg.proposal',
                'admissions.pg.supervisor',
                'admissions.pg.panel',
                'admissions.recommend',
                'admissions.approve',
                'admissions.offer',
                'students.view_any',
            ])->pluck('id')],
            'hostel-officer' => ['Hostel Officer', true, Permission::query()->where('module', 'hostel')->pluck('id')],
            'student' => ['Student', true, Permission::query()->whereIn('key', [
                'students.view_own', 'wallet.view_own', 'medical.view_own', 'documents.view_own', 'admissions.apply',
            ])->pluck('id')],
            'applicant' => ['Applicant', true, Permission::query()->whereIn('key', ['admissions.apply', 'documents.view_own'])->pluck('id')],
        ];
        foreach ($roles as $slug => [$name, $system, $ids]) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $name, 'is_system' => $system, 'is_active' => true]
            );
            $role->permissions()->sync($ids);
        }

        Setting::setValue('university_name', 'Bells University of Technology');
        Setting::setValue('university_motto', 'Chords of Knowledge');
        Setting::setValue('admissions.email', 'admissions@bellsuniversity.edu.ng');
        Setting::setValue('admissions.phone', '');
        Setting::setValue('staff_support.label', 'ICT & Registry support');
        Setting::setValue('staff_support.email', 'ict@bellsuniversity.edu.ng');
        Setting::setValue('staff_support.phone', '+234 801 000 0000');
        Setting::setValue('maintenance', '0');
        Setting::setValue('security.two_factor_enabled', '0');
        Setting::setValue('security.password_rotation_days', '0');
        Setting::setValue('security.inactivity_logout_minutes', '0');
        Setting::setValue('studentship.years_after_graduation', '2');

        $user = User::query()->firstOrCreate(
            ['email' => 'support@cyctechservices.com'],
            [
                'name' => 'Super Admin',
                'password' => 'Password1!',
                'status' => 'active',
            ]
        );
        $user->roles()->sync([Role::query()->where('slug', 'super-admin')->value('id')]);
        if (! $user->staff) {
            Staff::query()->create([
                'user_id' => $user->id,
                'staff_number' => 'STF-SUP-'.$user->id,
                'title' => 'Super Admin',
            ]);
        }
    }
}
