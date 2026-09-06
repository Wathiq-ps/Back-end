<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * The MVP's single tenant. See app.tenants migration: tenant_id is on every
 * business table for future multi-tenancy, but only one row exists for now.
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::firstOrCreate(
            ['slug' => 'default'],
            ['name_ar' => 'وثيق', 'name_en' => 'Wathiq', 'status' => 'active'],
        );
    }
}
