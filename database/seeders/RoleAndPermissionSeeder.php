<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleAndPermissionSeeder extends Seeder
{
    public function run()
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Buat Permissions untuk presence management
        Permission::create(['name' => 'view presences']);
        Permission::create(['name' => 'create presences']);
        Permission::create(['name' => 'edit presences']);
        Permission::create(['name' => 'delete presences']);
        Permission::create(['name' => 'view reports']);
        Permission::create(['name' => 'manage users']);

        // Buat Roles
        $staffRole = Role::create(['name' => 'staff']);
        $staffRole->givePermissionTo(['view presences']);

        $adminRole = Role::create(['name' => 'admin']);
        $adminRole->givePermissionTo(Permission::all());

        $managerRole = Role::create(['name' => 'manager']);
        $managerRole->givePermissionTo(['view presences', 'view reports']);
    }
}
