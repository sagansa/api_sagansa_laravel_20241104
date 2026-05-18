<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\PermitEmployee;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class PermitEmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create roles if they don't exist
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $staffRole = Role::firstOrCreate(['name' => 'staff']);

        // Create admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $admin->assignRole('admin');

        // Create staff users
        $staff1 = User::firstOrCreate(
            ['email' => 'john@example.com'],
            [
                'name' => 'John Doe',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $staff1->assignRole('staff');

        $staff2 = User::firstOrCreate(
            ['email' => 'jane@example.com'],
            [
                'name' => 'Jane Smith',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $staff2->assignRole('staff');

        // Create leave requests
        PermitEmployee::create([
            'created_by_id' => $staff1->id,
            'reason' => '1', // married
            'from_date' => now()->addDays(7),
            'until_date' => now()->addDays(9),
            'status' => '1', // pending
            'notes' => 'Honeymoon trip to Bali',
        ]);

        PermitEmployee::create([
            'created_by_id' => $staff2->id,
            'reason' => '2', // sick
            'from_date' => now()->subDays(2),
            'until_date' => now()->addDays(1),
            'status' => '2', // approved
            'notes' => 'Doctor recommended rest',
            'approved_by_id' => $admin->id,
            'approved_at' => now()->subDays(1),
            'admin_notes' => 'Approved by admin',
        ]);

        PermitEmployee::create([
            'created_by_id' => $staff1->id,
            'reason' => '4', // holiday
            'from_date' => now()->subDays(10),
            'until_date' => now()->subDays(8),
            'status' => '3', // rejected
            'notes' => 'Family vacation',
            'approved_by_id' => $admin->id,
            'approved_at' => now()->subDays(8),
            'admin_notes' => 'Too many requests in the same period',
        ]);

        PermitEmployee::create([
            'created_by_id' => $staff2->id,
            'reason' => '3', // hometown
            'from_date' => now()->addDays(14),
            'until_date' => now()->addDays(16),
            'status' => '1', // pending
            'notes' => 'Visit family in hometown',
        ]);

        PermitEmployee::create([
            'created_by_id' => $staff1->id,
            'reason' => '5', // family death
            'from_date' => now()->subDays(5),
            'until_date' => now()->subDays(3),
            'status' => '2', // approved
            'notes' => 'Grandfather passed away',
            'approved_by_id' => $admin->id,
            'approved_at' => now()->subDays(4),
            'admin_notes' => 'Condolences. Approved immediately.',
        ]);
    }
}
