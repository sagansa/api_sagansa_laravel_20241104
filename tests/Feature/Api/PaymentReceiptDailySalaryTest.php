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

    // ------------------------------------------------------------------
    // 5. GET /daily-salaries/employees → flag is_former_employee per user
    // ------------------------------------------------------------------

    public function test_employees_endpoint_returns_former_employee_flag(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'former-employee']);

        $admin = $this->userWithRole('admin');
        $active = $this->userWithRole('staff');
        $former = User::factory()->create();
        $former->assignRole('former-employee');

        $store = $this->store();
        $this->salary($store, $active->id, 100000);
        $this->salary($store, $former->id, 100000);

        Sanctum::actingAs($admin);
        $res = $this->getJson('/daily-salaries/employees');

        $res->assertOk()->assertJsonPath('success', true);

        $byId = collect($res->json('data'))->keyBy('id');
        $this->assertFalse($byId[$active->id]['is_former_employee']);
        $this->assertTrue($byId[$former->id]['is_former_employee']);
    }

    // ------------------------------------------------------------------
    // 6. GET /daily-salaries?employee_role= → filter aktif vs mantan
    // ------------------------------------------------------------------

    public function test_daily_salaries_index_filters_by_employee_role(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'former-employee']);

        $admin = $this->userWithRole('admin');
        $active = $this->userWithRole('staff');
        $former = User::factory()->create();
        $former->assignRole('former-employee');

        $store = $this->store();
        $this->salary($store, $active->id, 200000);
        $this->salary($store, $former->id, 150000);

        Sanctum::actingAs($admin);

        // Test DB tidak di-refresh antar run (data lama tetap ada), jadi
        // asersi dikombinasikan dengan user_id user yang baru dibuat agar
        // deterministik sekaligus tetap membuktikan employee_role diterapkan.

        // Karyawan aktif + filter active → record-nya ikut.
        $this->getJson('/daily-salaries?user_id=' . $active->id . '&employee_role=active')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.total_amount', 200000);

        // Karyawan aktif + filter former → record-nya tersaring habis.
        $this->getJson('/daily-salaries?user_id=' . $active->id . '&employee_role=former')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.total_amount', 0);

        // Mantan karyawan + filter former → record-nya ikut.
        $this->getJson('/daily-salaries?user_id=' . $former->id . '&employee_role=former')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.total_amount', 150000);
    }

    // ------------------------------------------------------------------
    // 7. Edit receipt: item add/remove tersinkron status dua arah
    // ------------------------------------------------------------------

    public function test_daily_salary_receipt_edit_syncs_items_and_status(): void
    {
        $admin = $this->userWithRole('admin');
        $employee = $this->userWithRole('staff');
        $store = $this->store();

        $salaryA = $this->salary($store, $employee->id, 200000, '1');
        $salaryB = $this->salary($store, $employee->id, 150000, '3');
        $salaryC = $this->salary($store, $employee->id, 100000, '3');

        Sanctum::actingAs($admin);
        $create = $this->postJson('/procurement/payment-receipts', [
            'payment_for' => 2,
            'daily_salary_ids' => [$salaryA->id, $salaryB->id],
            'transfer_amount' => 350000,
        ]);
        $create->assertCreated();
        $receiptId = $create->json('data.id');

        // Edit: buang B, tambah C; nominal transfer berubah.
        $res = $this->postJson("/procurement/daily-salary-payment-receipts/{$receiptId}", [
            'daily_salary_ids' => [$salaryA->id, $salaryC->id],
            'transfer_amount' => 300000,
            'notes' => 'revise',
        ]);

        $res->assertOk()->assertJsonPath('success', true);
        $res->assertJsonPath('data.transfer_amount', 300000);
        $res->assertJsonPath('data.total_amount', 300000); // 200k + 100k
        $res->assertJsonPath('data.user_id', $employee->id);
        $res->assertJsonPath('data.notes', 'revise');

        // B dilepas → kembali siap dibayar (3); A tetap dibayar; C jadi dibayar.
        $this->assertEquals('2', $salaryA->fresh()->status);
        $this->assertEquals('3', $salaryB->fresh()->status);
        $this->assertEquals('2', $salaryC->fresh()->status);

        // Pivot B hilang, A & C ter-attach.
        $this->assertDatabaseMissing('daily_salary_payment_receipt', [
            'daily_salary_id' => $salaryB->id,
        ]);
        $this->assertDatabaseHas('daily_salary_payment_receipt', [
            'daily_salary_id' => $salaryA->id,
        ]);
        $this->assertDatabaseHas('daily_salary_payment_receipt', [
            'daily_salary_id' => $salaryC->id,
        ]);
    }

    public function test_daily_salary_receipt_edit_rejects_mixed_employees(): void
    {
        $admin = $this->userWithRole('admin');
        $employeeA = $this->userWithRole('staff');
        $employeeB = $this->userWithRole('staff');
        $store = $this->store();

        $salaryA = $this->salary($store, $employeeA->id, 200000);
        $salaryB = $this->salary($store, $employeeB->id, 150000);

        Sanctum::actingAs($admin);
        $create = $this->postJson('/procurement/payment-receipts', [
            'payment_for' => 2,
            'daily_salary_ids' => [$salaryA->id],
            'transfer_amount' => 200000,
        ]);
        $create->assertCreated();
        $receiptId = $create->json('data.id');

        $this->postJson("/procurement/daily-salary-payment-receipts/{$receiptId}", [
            'daily_salary_ids' => [$salaryA->id, $salaryB->id],
            'transfer_amount' => 350000,
        ])->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Pilih daily salary dari karyawan yang sama.');

        // Tidak ada perubahan.
        $this->assertEquals('2', $salaryA->fresh()->status);
        $this->assertEquals('3', $salaryB->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 8. Hapus receipt: status semua salary kembali seperti semula (3)
    // ------------------------------------------------------------------

    public function test_daily_salary_receipt_delete_reverts_status(): void
    {
        $admin = $this->userWithRole('admin');
        $employee = $this->userWithRole('staff');
        $store = $this->store();

        $salaryA = $this->salary($store, $employee->id, 200000, '1');
        $salaryB = $this->salary($store, $employee->id, 150000, '3');

        Sanctum::actingAs($admin);
        $create = $this->postJson('/procurement/payment-receipts', [
            'payment_for' => 2,
            'daily_salary_ids' => [$salaryA->id, $salaryB->id],
            'transfer_amount' => 350000,
        ]);
        $create->assertCreated();
        $receiptId = $create->json('data.id');

        $this->assertEquals('2', $salaryA->fresh()->status);
        $this->assertEquals('2', $salaryB->fresh()->status);

        $this->deleteJson("/procurement/payment-receipts/{$receiptId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // Receipt + pivot hilang, status kembali siap dibayar (3).
        $this->assertDatabaseMissing('payment_receipts', ['id' => $receiptId]);
        $this->assertDatabaseMissing('daily_salary_payment_receipt', [
            'payment_receipt_id' => $receiptId,
        ]);
        $this->assertEquals('3', $salaryA->fresh()->status);
        $this->assertEquals('3', $salaryB->fresh()->status);
    }
}