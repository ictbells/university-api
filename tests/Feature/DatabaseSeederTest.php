<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_seed_requires_super_admin_email_and_password(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.super_admin_email' => '',
            'app.super_admin_password' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Production seeding requires SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD');

        $this->seed(DatabaseSeeder::class);
    }

    public function test_production_seed_rejects_default_password(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.super_admin_email' => 'superadmin@bellsuniversity.edu.ng',
            'app.super_admin_password' => 'Password1!',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUPER_ADMIN_PASSWORD');

        $this->seed(DatabaseSeeder::class);
    }
}
