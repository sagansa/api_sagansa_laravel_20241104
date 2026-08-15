<?php

namespace Tests\Feature\Api;

use App\Models\DailySalary;
use App\Models\PaymentType;
use App\Models\ShiftStore;
use App\Models\Store;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD daily salary manual dari mobile (meniru DailySalaryResource admin):
 * create selalu created_by_id = user login + status 1, update hanya milik
 * sendiri (atau admin) dan hanya selama belum dibayar (status != 2), delete
 * tidak boleh untuk yang sudah dibayar / sudah terikat payment receipt.
 */
class DailySalaryCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!PaymentType::find(1)) {
            PaymentType::insert(['id' => 1, 'name' => 'Transfer', 'status' => 1]);
        }
        if (!PaymentType::find(2)) {
            PaymentType::insert(['id' => 2, 'name' => 'Tunai', 'status' => 1]);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    private function store(): Store
    {
        return Store::factory()->create();
    }

    private function shiftStore(): ShiftStore
    {
        return ShiftStore::factory()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'store_id' => $this->store()->id,
            'shift_store_id' => $this->shiftStore()->id,
            'date' => now()->toDateString(),
            'amount' => 50000,
            'payment_type_id' => 1,
        ], $overrides);
    }

    public function test_staff_can_create_own_daily_salary(): void
    {
        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);

        $res = $this->postJson('/daily-salaries', $this->payload());

        $res->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.created_by_id', $staff->id);

        $this->assertDatabaseHas('daily_salaries', [
            'created_by_id' => $staff->id,
            'status' => 1,
            'amount' => 50000,
        ]);
    }

    public function test_create_rejects_duplicate_date_per_employee(): void
    {
        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);

        $res = $this->postJson('/daily-salaries', $this->payload(['date' => '2026-08-15']));
        $res->assertCreated();

        $res2 = $this->postJson('/daily-salaries', $this->payload(['date' => '2026-08-15']));
        $res2->assertStatus(422);
    }

    public function test_update_rejects_duplicate_date_per_employee(): void
    {
        $staff = $this->userWithRole('staff');
        $one = DailySalary::create([
            'store_id' => $this->store()->id,
            'shift_store_id' => $this->shiftStore()->id,
            'date' => '2026-08-14',
            'amount' => 50000,
            'payment_type_id' => 1,
            'status' => 1,
            'created_by_id' => $staff->id,
        ]);
        $two = DailySalary::create([
            'store_id' => $this->store()->id,
            'shift_store_id' => $this->shiftStore()->id,
            'date' => '2026-08-15',
            'amount' => 50000,
            'payment_type_id' => 1,
            'status' => 1,
            'created_by_id' => $staff->id,
        ]);
        Sanctum::actingAs($staff);

        // Pindahkan record kedua ke tanggal record pertama → duplikat.
        $res = $this->putJson("/daily-salaries/{$two->id}", ['date' => $one->date]);

        $res->assertStatus(422);
        $this->assertEquals('2026-08-15', $two->fresh()->date);

        // Tanggal yang sama dengan miliknya sendiri (tanpa perubahan) tetap boleh.
        $res2 = $this->putJson("/daily-salaries/{$two->id}", [
            'date' => '2026-08-15',
            'amount' => 60000,
        ]);
        $res2->assertOk();
    }

    public function test_staff_can_update_own_unpaid_daily_salary_and_cannot_change_status(): void
    {
        $staff = $this->userWithRole('staff');
        $salary = DailySalary::create([
            'store_id' => $this->store()->id,
            'shift_store_id' => $this->shiftStore()->id,
            'date' => '2026-08-15',
            'amount' => 50000,
            'payment_type_id' => 1,
            'status' => 1,
            'created_by_id' => $staff->id,
        ]);
        Sanctum::actingAs($staff);

        $res = $this->putJson("/daily-salaries/{$salary->id}", [
            'amount' => 75000,
            'payment_type_id' => 2,
            // Percobaan ubah status & pemilik harus diabaikan.
            'status' => 3,
            'created_by_id' => 999,
        ]);

        $res->assertOk()->assertJsonPath('success', true);
        $fresh = $salary->fresh();
        $this->assertEquals(75000, $fresh->amount);
        $this->assertEquals(2, $fresh->payment_type_id);
        $this->assertEquals(1, $fresh->status);
        $this->assertEquals($staff->id, $fresh->created_by_id);
    }

    public function test_staff_cannot_update_others_or_paid_daily_salary(): void
    {
        $staff = $this->userWithRole('staff');
        $other = $this->userWithRole('staff');
        Sanctum::actingAs($staff);

        $others = DailySalary::create([
            'store_id' => $this->store()->id,
            'shift_store_id' => $this->shiftStore()->id,
            'date' => '2026-08-15',
            'amount' => 50000,
            'payment_type_id' => 1,
            'status' => 1,
            'created_by_id' => $other->id,
        ]);
        $res = $this->putJson("/daily-salaries/{$others->id}", ['amount' => 1]);
        $res->assertStatus(403);

        $paid = DailySalary::create([
            'store_id' => $this->store()->id,
            'shift_store_id' => $this->shiftStore()->id,
            'date' => '2026-08-14',
            'amount' => 50000,
            'payment_type_id' => 1,
            'status' => 2,
            'created_by_id' => $staff->id,
        ]);
        $res2 = $this->putJson("/daily-salaries/{$paid->id}", ['amount' => 1]);
        $res2->assertStatus(400);
    }

    public function test_admin_can_update_any_daily_salary(): void
    {
        $admin = $this->userWithRole('admin');
        $staff = $this->userWithRole('staff');
        $salary = DailySalary::create([
            'store_id' => $this->store()->id,
            'shift_store_id' => $this->shiftStore()->id,
            'date' => '2026-08-15',
            'amount' => 50000,
            'payment_type_id' => 1,
            'status' => 1,
            'created_by_id' => $staff->id,
        ]);
        Sanctum::actingAs($admin);

        $res = $this->putJson("/daily-salaries/{$salary->id}", ['amount' => 60000]);

        $res->assertOk();
        $this->assertEquals(60000, $salary->fresh()->amount);
    }

    public function test_staff_can_delete_own_unpaid_daily_salary(): void
    {
        $staff = $this->userWithRole('staff');
        $salary = DailySalary::create([
            'store_id' => $this->store()->id,
            'shift_store_id' => $this->shiftStore()->id,
            'date' => '2026-08-15',
            'amount' => 50000,
            'payment_type_id' => 1,
            'status' => 1,
            'created_by_id' => $staff->id,
        ]);
        Sanctum::actingAs($staff);

        $res = $this->deleteJson("/daily-salaries/{$salary->id}");

        $res->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseMissing('daily_salaries', ['id' => $salary->id]);
    }

    public function test_cannot_delete_paid_daily_salary(): void
    {
        $staff = $this->userWithRole('staff');
        $salary = DailySalary::create([
            'store_id' => $this->store()->id,
            'shift_store_id' => $this->shiftStore()->id,
            'date' => '2026-08-15',
            'amount' => 50000,
            'payment_type_id' => 1,
            'status' => 2,
            'created_by_id' => $staff->id,
        ]);
        Sanctum::actingAs($staff);

        $res = $this->deleteJson("/daily-salaries/{$salary->id}");

        $res->assertStatus(400);
        $this->assertDatabaseHas('daily_salaries', ['id' => $salary->id]);
    }
}
