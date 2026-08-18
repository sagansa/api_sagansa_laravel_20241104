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

        // Buat Permissions untuk presence management (idempoten).
        Permission::firstOrCreate(['name' => 'view presences']);
        Permission::firstOrCreate(['name' => 'create presences']);
        Permission::firstOrCreate(['name' => 'edit presences']);
        Permission::firstOrCreate(['name' => 'delete presences']);
        Permission::firstOrCreate(['name' => 'view reports']);
        Permission::firstOrCreate(['name' => 'manage users']);

        // Buat Roles (idempoten — aman dijalankan berulang).
        $staffRole = Role::firstOrCreate(['name' => 'staff']);
        $staffRole->syncPermissions(['view presences']);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->syncPermissions(Permission::all());

        $managerRole = Role::firstOrCreate(['name' => 'manager']);
        $managerRole->syncPermissions(['view presences', 'view reports']);

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdminRole->syncPermissions(Permission::all());

        // Role storage-staff (staff gudang) — penerima notifikasi sales order
        // online & pengingat aset.
        $storageStaffRole = Role::firstOrCreate(['name' => 'storage-staff']);
        $storageStaffRole->syncPermissions(['view presences']);
    }
}
