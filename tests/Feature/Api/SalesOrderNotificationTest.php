<?php

namespace Tests\Feature\Api;

use App\Models\DeliveryService;
use App\Models\Notification;
use App\Models\OnlineShopProvider;
use App\Models\PaymentType;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\FcmService;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Menguji notifikasi sales order online:
 *  - POST /sales-orders/online membuat row `notifications` untuk SEMUA user
 *    ber-role storage-staff (kecuali pembuat), bukan admin/creator.
 *  - GET /sales-orders/online/{id} mengembalikan order + items (deep-link).
 *
 * FcmService di-swap dengan mock sehingga tidak benar-benar memanggil Firebase.
 */
class SalesOrderNotificationTest extends TestCase
{
    private FcmService $fcm;

    /** @var array<int> user_id yang menerima push (hasDevice selalu true di mock). */
    private array $sentTo = [];

    /** @var array<int> user_id yang dibuat selama test, untuk cleanup notif. */
    private array $trackedUserIds = [];

    /** @var array<int> id order online yang dibuat selama test, untuk cleanup. */
    private array $trackedOrderIds = [];

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
        if (! empty($this->trackedOrderIds)) {
            Notification::whereIn(
                'user_id',
                $this->trackedUserIds,
            )->where('type', 'sales_order_online_created')->delete();

            \DB::table('detail_sales_orders')
                ->whereIn('sales_order_id', $this->trackedOrderIds)
                ->delete();
            \DB::table('sales_orders')
                ->whereIn('id', $this->trackedOrderIds)
                ->delete();
        }

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

    private function ensurePaymentType(int $id, string $name): void
    {
        if (! PaymentType::find($id)) {
            PaymentType::insert(['id' => $id, 'name' => $name, 'status' => 1]);
        }
    }

    private function store(): Store
    {
        return Store::factory()->create();
    }

    /**
     * Bangun produk lengkap (unit + kategori) + payload POST /sales-orders/online.
     */
    private function makeOnlineOrderPayload(Store $store, User $creator): array
    {
        $this->ensurePaymentType(1, 'Transfer');

        $provider = OnlineShopProvider::create(['name' => 'Shopee ' . uniqid()]);
        $delivery = DeliveryService::create(['name' => 'JNE ' . uniqid(), 'status' => 1]);

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

        return [
            'store_id' => $store->id,
            'delivery_date' => now()->toDateString(),
            'online_shop_provider_id' => $provider->id,
            'delivery_service_id' => $delivery->id,
            'receipt_no' => 'SO-' . strtoupper(uniqid()),
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 150000,
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // 1. POST /sales-orders/online → notifikasi semua storage-staff
    // ------------------------------------------------------------------

    public function test_store_online_endpoint_notifies_all_storage_staff_not_creator(): void
    {
        $creator = $this->userWithRole('admin');
        $staffA = $this->userWithRole('storage-staff');
        $staffB = $this->userWithRole('storage-staff');
        $otherAdmin = $this->userWithRole('admin');

        $store = $this->store();
        $payload = $this->makeOnlineOrderPayload($store, $creator);

        Sanctum::actingAs($creator);
        $res = $this->postJson('/sales-orders/online', $payload);
        $res->assertCreated();

        // FCM push ke semua storage-staff, bukan creator/otherAdmin.
        $this->assertContains($staffA->id, $this->sentTo);
        $this->assertContains($staffB->id, $this->sentTo);
        $this->assertNotContains($creator->id, $this->sentTo);
        $this->assertNotContains($otherAdmin->id, $this->sentTo);

        // Row notifikasi untuk semua storage-staff, bukan creator/otherAdmin.
        $this->assertDatabaseHas('notification_center', [
            'user_id' => $staffA->id,
            'type' => 'sales_order_online_created',
        ]);
        $this->assertDatabaseHas('notification_center', [
            'user_id' => $staffB->id,
            'type' => 'sales_order_online_created',
        ]);
        $this->assertDatabaseMissing('notification_center', [
            'user_id' => $creator->id,
            'type' => 'sales_order_online_created',
        ]);
        $this->assertDatabaseMissing('notification_center', [
            'user_id' => $otherAdmin->id,
            'type' => 'sales_order_online_created',
        ]);

        // Row membawa sales_order_id di kolom data.
        $orderId = (int) $res->json('data.id');
        $row = Notification::where('user_id', $staffA->id)
            ->where('type', 'sales_order_online_created')
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals($orderId, (int) $row->data['sales_order_id']);
        $this->assertArrayHasKey('receipt_no', $row->data);
    }

    public function test_store_online_fcm_failure_does_not_break_request(): void
    {
        // FcmService yang me-throw saat mengirim.
        $failing = Mockery::mock(FcmService::class);
        $failing->shouldReceive('hasDevice')->andReturn(true);
        $failing->shouldReceive('sendToUser')->andThrow(new \RuntimeException('FCM down'));
        $this->app->instance(FcmService::class, $failing);

        $creator = $this->userWithRole('admin');
        $staff = $this->userWithRole('storage-staff');

        $store = $this->store();
        $payload = $this->makeOnlineOrderPayload($store, $creator);

        Sanctum::actingAs($creator);
        $res = $this->postJson('/sales-orders/online', $payload);

        // Response tetap sukses 201 meski FCM gagal.
        $res->assertCreated();

        // Row notifikasi tetap tercipta (persist terpisah dari FCM).
        $this->assertDatabaseHas('notification_center', [
            'user_id' => $staff->id,
            'type' => 'sales_order_online_created',
        ]);
    }

    // ------------------------------------------------------------------
    // 2. GET /sales-orders/online/{id} → order + items
    // ------------------------------------------------------------------

    public function test_show_online_endpoint_returns_order_with_items(): void
    {
        $creator = $this->userWithRole('admin');
        $staff = $this->userWithRole('storage-staff');

        $store = $this->store();
        $payload = $this->makeOnlineOrderPayload($store, $creator);

        Sanctum::actingAs($creator);
        $created = $this->postJson('/sales-orders/online', $payload);
        $created->assertCreated();
        $orderId = (int) $created->json('data.id');
        $this->trackedOrderIds[] = $orderId;

        // Akses bebas (auth:sanctum saja), bahkan sebagai storage-staff.
        Sanctum::actingAs($staff);
        $res = $this->getJson("/sales-orders/online/{$orderId}");
        $res->assertOk();
        $res->assertJsonPath('success', true);

        $data = $res->json('data');
        $this->assertEquals($orderId, (int) $data['id']);
        $this->assertEquals($payload['receipt_no'], $data['receipt_no']);
        $this->assertEquals('1', (string) $data['delivery_status']);

        // Items ikut di-response.
        $this->assertNotEmpty($data['items']);
        $this->assertEquals(2, (int) $data['items'][0]['quantity']);
        $this->assertEquals(300000, (int) $data['total_price']);
    }

    public function test_show_online_returns_404_for_unknown_order(): void
    {
        $staff = $this->userWithRole('storage-staff');

        Sanctum::actingAs($staff);
        $res = $this->getJson('/sales-orders/online/999999999');
        $res->assertStatus(404);
    }
}