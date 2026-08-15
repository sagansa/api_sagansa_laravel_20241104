<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed role & permission bila belum ada. Dibutuhkan karena RefreshDatabase
     * melakukan migrate:fresh (tabel roles kosong kembali), sementara banyak
     * test memanggil assignRole() tanpa seeding sendiri.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (app()->environment('testing') && \Spatie\Permission\Models\Role::query()->doesntExist()) {
            Artisan::call('db:seed', ['--class' => 'RoleAndPermissionSeeder', '--force' => true]);
        }
    }
}
