<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Presence;
use App\Models\Store;
use App\Models\ShiftStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class ExportPresenceTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create admin user
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        
        // Create staff user
        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');
        
        // Create store and shift
        $this->store = Store::factory()->create();
        $this->shiftStore = ShiftStore::factory()->create();
        
        // Create some presence records
        Presence::factory()->count(5)->create([
            'created_by_id' => $this->staff->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
        ]);
    }

    public function test_admin_can_export_presence_data_to_excel()
    {
        Sanctum::actingAs($this->admin);
        
        $response = $this->postJson('/api/admin/presences/export', [
            'format' => 'excel',
            'date_from' => now()->subDays(7)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'id',
                        'status',
                        'progress',
                        'file_url',
                        'file_name',
                        'file_size',
                        'total_records',
                        'format',
                        'created_at',
                        'completed_at',
                    ]
                ]);

        $this->assertTrue($response->json('success'));
        $this->assertEquals('completed', $response->json('data.status'));
        $this->assertEquals('excel', $response->json('data.format'));
        $this->assertStringContains('.xlsx', $response->json('data.file_name'));
    }

    public function test_admin_can_export_presence_data_to_pdf()
    {
        Sanctum::actingAs($this->admin);
        
        $response = $this->postJson('/api/admin/presences/export', [
            'format' => 'pdf',
            'date_from' => now()->subDays(7)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertEquals('completed', $response->json('data.status'));
        $this->assertEquals('pdf', $response->json('data.format'));
        $this->assertStringContains('.pdf', $response->json('data.file_name'));
    }

    public function test_admin_can_export_presence_data_to_csv()
    {
        Sanctum::actingAs($this->admin);
        
        $response = $this->postJson('/api/admin/presences/export', [
            'format' => 'csv',
            'date_from' => now()->subDays(7)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertEquals('completed', $response->json('data.status'));
        $this->assertEquals('csv', $response->json('data.format'));
        $this->assertStringContains('.csv', $response->json('data.file_name'));
    }

    public function test_export_validates_required_format()
    {
        Sanctum::actingAs($this->admin);
        
        $response = $this->postJson('/api/admin/presences/export', [
            'date_from' => now()->subDays(7)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['format']);
    }

    public function test_export_validates_invalid_format()
    {
        Sanctum::actingAs($this->admin);
        
        $response = $this->postJson('/api/admin/presences/export', [
            'format' => 'invalid',
            'date_from' => now()->subDays(7)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['format']);
    }

    public function test_export_validates_date_range()
    {
        Sanctum::actingAs($this->admin);
        
        $response = $this->postJson('/api/admin/presences/export', [
            'format' => 'excel',
            'date_from' => now()->format('Y-m-d'),
            'date_to' => now()->subDays(7)->format('Y-m-d'),
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['date_to']);
    }

    public function test_non_admin_cannot_export_presence_data()
    {
        Sanctum::actingAs($this->staff);
        
        $response = $this->postJson('/api/admin/presences/export', [
            'format' => 'excel',
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_export_presence_data()
    {
        $response = $this->postJson('/api/admin/presences/export', [
            'format' => 'excel',
        ]);

        $response->assertStatus(401);
    }
}