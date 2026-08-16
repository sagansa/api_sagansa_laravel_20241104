<?php

namespace Tests\Feature\Api;

use App\Models\DailySalary;
use App\Models\PaymentType;
use App\Models\Store;
use App\Models\User;
use App\Services\FcmService;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Perbaikan payment receipt daily salary (payment_for = 2):
 *  - atribusi receipt: user_id diisi created_by_id salary (penerima gaji),
 *    bukan admin yang login;
 *  - validasi "satu karyawan" di sisi backend (aturan yang tadinya hanya
 *    ada di client mobile);
 *  - notifikasi: karyawan penerima tetap ter-notifikasi, admin pembayar
 *    tidak (payer eksplisit, bukan receipt->user_id);
 *  - GET /daily-salaries mengembalikan meta.total_amount (sum hasil filter).
 */
class PaymentReceiptDailySalaryTest extends TestCase
{
    private FcmService $fcm;

    /** @var array<int> user_id yang menerima push (hasDevice selalu true di mock). */
    private array $sentTo = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!PaymentType::find(1)) {
            PaymentType::insert(['id' => 1, 'name' => 'Transfer', 'status' => 1]);
        }

        $this->sentTo = [];
        $this->fcm = Mockery::mock(FcmService::class);
        $this->fcm->shouldReceive('hasDevice')->andReturn(true);
        $this->fcm->shouldReceive('sendToUser')
            ->andReturnUsing(function ($userId, $data) {
                $this->sentTo[] = $userId;
                return 1;
            });
        $this->app->instance(FcmService::class, $this->fcm);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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

    private function salary(Store $store, int $ownerId, int $amount, string $status = '3'): DailySalary
    {
        return DailySalary::create([
            'store_id' => $store->id,
            'amount' => $amount,
            'payment_type_id' => 1,
            'status' => $status,
            'created_by_id' => $ownerId,
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Campur 2 karyawan → ditolak 400 (aturan tadinya hanya di mobile)
    // ------------------------------------------------------------------

    public function test_daily_salary_receipt_rejects_mixed_employees(): void
    {
        $admin = $this->userWithRole('admin');
        $employeeA = $this->userWithRole('staff');
        $employeeB = $this->userWithRole('staff');
        $store = $this->store();

        $salaryA = $this->salary($store, $employeeA->id, 200000);
        $salaryB = $this->salary($store, $employeeB->id, 150000);

        Sanctum::actingAs($admin);
        $res = $this->postJson('/procurement/payment-receipts', [
            'payment_for' => 2,
            'daily_salary_ids' => [$salaryA->id, $salaryB->id],
            'transfer_amount' => 350000,
        ]);

        $res->assertStatus(400);
        $res->assertJsonPath('success', false);
        $res->assertJsonPath('message', 'Pilih daily salary dari karyawan yang sama.');

        // Tidak ada receipt baru dibuat & salary tidak berubah.
        $this->assertDatabaseMissing('daily_salary_payment_receipt', [
            'daily_salary_id' => $salaryA->id,
        ]);
        $this->assertDatabaseMissing('daily_salary_payment_receipt', [
            'daily_salary_id' => $salaryB->id,
        ]);
        $this->assertEquals('3', $salaryA->fresh()->status);
        $this->assertEquals('3', $salaryB->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 2. Create sukses → user_id = created_by_id salary; status semua = 2
    // ------------------------------------------------------------------

    public function test_daily_salary_receipt_attributes_user_to_salary_owner(): void
    {
        $admin = $this->userWithRole('admin');
        $employee = $this->userWithRole('staff');
        $store = $this->store();

        $salaryA = $this->salary($store, $employee->id, 200000, '1');
        $salaryB = $this->salary($store, $employee->id, 150000, '3');

        Sanctum::actingAs($admin);
        $res = $this->postJson('/procurement/payment-receipts', [
            'payment_for' => 2,
            'daily_salary_ids' => [$salaryA->id, $salaryB->id],
            'transfer_amount' => 350000,
        ]);

        $res->assertCreated()
            ->assertJsonPath('success', true)
            // Penerima receipt = karyawan pemilik salary, bukan admin yang login.
            ->assertJsonPath('data.user_id', $employee->id);

        $this->assertDatabaseHas('payment_receipts', [
            'payment_for' => '2',
            'user_id' => $employee->id,
        ]);

        // Semua salary yang dibayar berubah status menjadi 2 (dibayar).
        $this->assertEquals('2', $salaryA->fresh()->status);
        $this->assertEquals('2', $salaryB->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 3. Notifikasi: karyawan penerima ter-notifikasi, admin pembayar tidak
    // ------------------------------------------------------------------

    public function test_daily_salary_receipt_notifies_employee_not_admin_payer(): void
    {
        $admin = $this->userWithRole('admin');
        $employee = $this->userWithRole('staff');
        $store = $this->store();

        $salary = $this->salary($store, $employee->id, 200000);

        Sanctum::actingAs($admin);
        $res = $this->postJson('/procurement/payment-receipts', [
            'payment_for' => 2,
            'daily_salary_ids' => [$salary->id],
            'transfer_amount' => 200000,
        ]);

        $res->assertCreated();

        // receipt->user_id kini = employee; payer eksplisit = admin. Maka
        // employee (penerima) tetap dapat notifikasi & admin (pembayar) tidak.
        $this->assertEquals($employee->id, $res->json('data.user_id'));
        $this->assertContains($employee->id, $this->sentTo);
        $this->assertNotContains($admin->id, $this->sentTo);
    }

    // ------------------------------------------------------------------
    // 4. GET /daily-salaries → meta.total_amount = sum hasil filter
    // ------------------------------------------------------------------

    public function test_daily_salaries_index_returns_filtered_total_amount(): void
    {
        $admin = $this->userWithRole('admin');
        $employeeA = $this->userWithRole('staff');
        $employeeB = $this->userWithRole('staff');
        $store = $this->store();

        // Employee A: 3 record (200k + 150k + 100k = 450k) di tanggal berbeda.
        $this->salary($store, $employeeA->id, 200000);
        $this->salary($store, $employeeA->id, 150000);
        $this->salary($store, $employeeA->id, 100000);

        // Employee B: 1 record (300k) — tidak masuk filter user_id.
        $this->salary($store, $employeeB->id, 300000);

        Sanctum::actingAs($admin);
        $res = $this->getJson('/daily-salaries?user_id=' . $employeeA->id);

        $res->assertOk()->assertJsonPath('success', true);
        $res->assertJsonPath('meta.total', 3);
        $res->assertJsonPath('meta.total_amount', 450000);

        // Filter tanggal: hanya record dengan date <= hari ini ikut (semua),
        // lalu kombinasi user_id + status tetap konsisten dengan sum.
        $res2 = $this->getJson(
            '/daily-salaries?user_id=' . $employeeA->id . '&status=3'
        );
        $res2->assertOk()->assertJsonPath('meta.total_amount', 450000);
    }
}