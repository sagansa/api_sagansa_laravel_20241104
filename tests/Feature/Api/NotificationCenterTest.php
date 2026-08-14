<?php

namespace Tests\Feature\Api;

use App\Models\DailySalary;
use App\Models\FuelService;
use App\Models\InvoicePurchase;
use App\Models\Notification;
use App\Models\PaymentReceipt;
use App\Models\PaymentType;
use App\Models\Product;
use App\Models\RequestPurchase;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FcmService;
use App\Services\ProcurementNotificationService;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Menguji Notification Center:
 *  - Penulisan row `notifications` saat invoice transfer / payment receipt dibuat.
 *  - Endpoint list / unread-count / read / read-all + isolasi antar user.
 *
 * FcmService di-swap dengan mock sehingga tidak memanggil Firebase.
 */
class NotificationCenterTest extends TestCase
{
    private FcmService $fcm;

    /** @var array<int> user_id yang menerima push (hasDevice selalu true di mock). */
    private array $sentTo = [];

    /** @var array<int> user_id yang dibuat selama test, untuk cleanup notif. */
    private array $trackedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

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
        // Bersihkan row notifikasi yang dibuat selama test agar tidak
        // mengganggu test lain (suite ini tidak memakai RefreshDatabase).
        if (! empty($this->trackedUserIds)) {
            Notification::whereIn('user_id', $this->trackedUserIds)->delete();
        }

        Mockery::close();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->trackedUserIds[] = $user->id;
        return $user;
    }

    private function store(): Store
    {
        return Store::factory()->create();
    }

    private function ensurePaymentType(int $id, string $name): void
    {
        if (! PaymentType::find($id)) {
            PaymentType::insert(['id' => $id, 'name' => $name, 'status' => 1]);
        }
    }

    /**
     * Bangun data pendukung + payload untuk store invoice via endpoint.
     */
    private function makeInvoicePayload(User $creator): array
    {
        $store = $this->store();
        $supplier = Supplier::create(['name' => 'Supp ' . uniqid(), 'status' => 1]);
        $requestPurchase = RequestPurchase::create([
            'store_id' => $store->id,
            'date' => now()->toDateString(),
            'status' => 1,
        ]);

        $u = \DB::table('units')->insertGetId(['name' => 'pcs', 'unit' => 'pcs']);
        $mg = \DB::table('material_groups')->insertGetId(['name' => 'mg', 'status' => 1]);
        $oc = \DB::table('online_categories')->insertGetId(['name' => 'oc', 'status' => 1]);
        $pg = \DB::table('product_groups')->insertGetId(['name' => 'pg']);
        $product = Product::create([
            'name' => 'Prod ' . uniqid(),
            'slug' => 'prod-' . uniqid(),
            'request' => 1,
            'remaining' => 1,
            'unit_id' => $u,
            'material_group_id' => $mg,
            'online_category_id' => $oc,
            'product_group_id' => $pg,
            'payment_type_id' => 1,
            'user_id' => $creator->id,
        ]);

        $detailRequest = \DB::table('detail_requests')->insertGetId([
            'product_id' => $product->id,
            'quantity_plan' => 2,
            'status' => 4,
            'request_purchase_id' => $requestPurchase->id,
            'store_id' => $store->id,
            'payment_type_id' => 1,
        ]);

        return [
            'supplier_id' => $supplier->id,
            'store_id' => $store->id,
            'date' => now()->toDateString(),
            'items' => [
                [
                    'detail_request_id' => $detailRequest,
                    'quantity_product' => 2,
                    'subtotal_invoice' => 1500000,
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // 1. Invoice Transfer via endpoint → row untuk admin, bukan creator
    // ------------------------------------------------------------------

    public function test_store_invoice_transfer_endpoint_creates_notification_rows(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $creator = $this->userWithRole('staff');
        $admin = $this->userWithRole('admin');
        $superAdmin = $this->userWithRole('super_admin');
        $otherStaff = $this->userWithRole('staff');

        $payload = $this->makeInvoicePayload($creator);
        $payload['payment_type_id'] = 1;

        Sanctum::actingAs($creator);
        $res = $this->postJson('/procurement/invoices', $payload);
        $res->assertCreated();

        // FCM push ke admin/super_admin, bukan creator/otherStaff.
        $this->assertContains($admin->id, $this->sentTo);
        $this->assertContains($superAdmin->id, $this->sentTo);
        $this->assertNotContains($creator->id, $this->sentTo);
        $this->assertNotContains($otherStaff->id, $this->sentTo);

        // Row notifikasi tercipta untuk admin & super_admin, bukan creator.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'invoice_transfer_created',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $superAdmin->id,
            'type' => 'invoice_transfer_created',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $creator->id,
            'type' => 'invoice_transfer_created',
        ]);

        // Row membawa invoice_id di kolom data.
        $row = Notification::where('user_id', $admin->id)
            ->where('type', 'invoice_transfer_created')
            ->first();
        $this->assertNotNull($row);
        $this->assertArrayHasKey('invoice_id', $row->data);
    }

    public function test_store_cash_invoice_endpoint_does_not_create_notification_row(): void
    {
        $this->ensurePaymentType(1, 'Transfer');
        $this->ensurePaymentType(2, 'Cash');

        $creator = $this->userWithRole('staff');
        $admin = $this->userWithRole('admin');

        $payload = $this->makeInvoicePayload($creator);
        $payload['payment_type_id'] = 2;

        Sanctum::actingAs($creator);
        $res = $this->postJson('/procurement/invoices', $payload);
        $res->assertCreated();

        $this->assertEmpty($this->sentTo, 'Invoice Cash tidak boleh memicu push.');
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $admin->id,
            'type' => 'invoice_transfer_created',
        ]);
    }

    // ------------------------------------------------------------------
    // 2. Payment Receipt → row untuk created_by, bukan payer (3 jenis)
    // ------------------------------------------------------------------

    public function test_payment_receipt_invoice_branch_notifies_creators(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $payer = $this->userWithRole('staff');
        $ownerA = $this->userWithRole('staff');
        $ownerB = $this->userWithRole('staff');
        $store = $this->store();

        $invA = InvoicePurchase::factory()->create([
            'store_id' => $store->id,
            'payment_type_id' => 1,
            'created_by_id' => $ownerA->id,
        ]);
        $invB = InvoicePurchase::factory()->create([
            'store_id' => $store->id,
            'payment_type_id' => 1,
            'created_by_id' => $ownerB->id,
        ]);

        $receipt = PaymentReceipt::create([
            'payment_for' => 3,
            'total_amount' => 1000000,
            'transfer_amount' => 1000000,
            'user_id' => $payer->id,
        ]);
        $receipt->invoicePurchases()->attach([$invA->id, $invB->id]);

        app(ProcurementNotificationService::class)->notifyPaymentReceiptPaid($receipt);

        $this->assertContains($ownerA->id, $this->sentTo);
        $this->assertContains($ownerB->id, $this->sentTo);
        $this->assertNotContains($payer->id, $this->sentTo);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $ownerA->id,
            'type' => 'payment_receipt_paid',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $ownerB->id,
            'type' => 'payment_receipt_paid',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $payer->id,
            'type' => 'payment_receipt_paid',
        ]);

        $row = Notification::where('user_id', $ownerA->id)
            ->where('type', 'payment_receipt_paid')
            ->first();
        $this->assertEquals($receipt->id, $row->data['receipt_id']);
        $this->assertEquals('3', $row->data['payment_for']);
    }

    public function test_payment_receipt_daily_salary_branch_notifies_creators(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $payer = $this->userWithRole('staff');
        $owner = $this->userWithRole('staff');
        $store = $this->store();

        $salary = DailySalary::create([
            'store_id' => $store->id,
            'amount' => 200000,
            'payment_type_id' => 1,
            'status' => 3,
            'created_by_id' => $owner->id,
        ]);

        $receipt = PaymentReceipt::create([
            'payment_for' => 2,
            'total_amount' => 200000,
            'transfer_amount' => 200000,
            'user_id' => $payer->id,
        ]);
        $receipt->dailySalaries()->attach([$salary->id]);

        app(ProcurementNotificationService::class)->notifyPaymentReceiptPaid($receipt);

        $this->assertContains($owner->id, $this->sentTo);
        $this->assertNotContains($payer->id, $this->sentTo);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'payment_receipt_paid',
        ]);
    }

    public function test_payment_receipt_fuel_service_branch_notifies_creators(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $payer = $this->userWithRole('staff');
        $owner = $this->userWithRole('staff');
        $store = $this->store();
        $vehicle = Vehicle::create([
            'no_register' => 'B' . rand(1000, 9999),
            'type' => 1,
            'store_id' => $store->id,
            'status' => 1,
        ]);

        $fuel = FuelService::create([
            'date' => now()->toDateString(),
            'vehicle_id' => $vehicle->id,
            'payment_type_id' => 1,
            'fuel_service' => 1,
            'km' => 100,
            'liter' => 10,
            'amount' => 150000,
            'created_by_id' => $owner->id,
            'status' => 1,
        ]);

        $receipt = PaymentReceipt::create([
            'payment_for' => 1,
            'total_amount' => 150000,
            'transfer_amount' => 150000,
            'user_id' => $payer->id,
        ]);
        $receipt->fuelServices()->attach([$fuel->id]);

        app(ProcurementNotificationService::class)->notifyPaymentReceiptPaid($receipt);

        $this->assertContains($owner->id, $this->sentTo);
        $this->assertNotContains($payer->id, $this->sentTo);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'payment_receipt_paid',
        ]);
    }

    // ------------------------------------------------------------------
    // 3. Endpoint Notification Center
    // ------------------------------------------------------------------

    public function test_notification_endpoints_list_unread_count_read_read_all(): void
    {
        $user = $this->userWithRole('admin');

        Notification::create([
            'user_id' => $user->id,
            'type' => 'invoice_transfer_created',
            'title' => 'Invoice Transfer Baru',
            'body' => 'Body 1',
            'data' => ['invoice_id' => '10'],
        ]);
        Notification::create([
            'user_id' => $user->id,
            'type' => 'payment_receipt_paid',
            'title' => 'Pembayaran Telah Dilakukan',
            'body' => 'Body 2',
            'data' => ['receipt_id' => '5', 'payment_for' => '3'],
        ]);

        Sanctum::actingAs($user);

        // unread-count = 2
        $countRes = $this->getJson('/notifications/unread-count');
        $countRes->assertOk();
        $this->assertEquals(2, $countRes->json('data.count'));

        // list
        $listRes = $this->getJson('/notifications');
        $listRes->assertOk();
        $this->assertCount(2, $listRes->json('data'));
        // Terbaru lebih dulu → notifikasi ke-2 (payment_receipt) di urutan 0.
        $this->assertEquals('payment_receipt_paid', $listRes->json('data.0.type'));

        // filter unread
        $unreadRes = $this->getJson('/notifications?unread=1');
        $unreadRes->assertOk();
        $this->assertCount(2, $unreadRes->json('data'));

        // mark one read
        $firstId = $listRes->json('data.0.id');
        $readRes = $this->postJson("/notifications/{$firstId}/read");
        $readRes->assertOk();
        $this->assertNotNull($readRes->json('data.read_at'));

        $countRes2 = $this->getJson('/notifications/unread-count');
        $this->assertEquals(1, $countRes2->json('data.count'));

        // mark all read
        $allRes = $this->postJson('/notifications/read-all');
        $allRes->assertOk();
        $countRes3 = $this->getJson('/notifications/unread-count');
        $this->assertEquals(0, $countRes3->json('data.count'));
    }

    public function test_notification_isolation_between_users(): void
    {
        $userA = $this->userWithRole('admin');
        $userB = $this->userWithRole('admin');

        Notification::create([
            'user_id' => $userA->id,
            'type' => 'invoice_transfer_created',
            'title' => 'A',
            'body' => 'body A',
            'data' => null,
        ]);
        Notification::create([
            'user_id' => $userB->id,
            'type' => 'invoice_transfer_created',
            'title' => 'B',
            'body' => 'body B',
            'data' => null,
        ]);

        Sanctum::actingAs($userA);
        $resA = $this->getJson('/notifications');
        $resA->assertOk();
        $ids = collect($resA->json('data'))->pluck('id')->all();
        $bIds = Notification::where('user_id', $userB->id)->pluck('id')->all();
        foreach ($bIds as $bId) {
            $this->assertNotContains($bId, $ids, 'User A tidak boleh melihat notif user B.');
        }
    }
}
