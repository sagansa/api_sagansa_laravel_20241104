<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('admin');

        // Create staff users
        $staff1 = User::create([
            'name' => 'Staff User 1',
            'email' => 'staff1@example.com',
            'password' => bcrypt('password'),
        ]);
        $staff1->assignRole('staff');

        $staff2 = User::create([
            'name' => 'Staff User 2',
            'email' => 'staff2@example.com',
            'password' => bcrypt('password'),
        ]);
        $staff2->assignRole('staff');

        // Create manager user
        $manager = User::create([
            'name' => 'Manager User',
            'email' => 'manager@example.com',
            'password' => bcrypt('password'),
        ]);
        $manager->assignRole('manager');

        // Create additional staff users using factory
        User::factory()
            ->count(3)
            ->create()
            ->each(function ($user) {
                $user->assignRole('staff');
            });
    }
}
