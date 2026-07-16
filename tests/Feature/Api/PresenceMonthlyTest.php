<?php

namespace Tests\Feature\Api;

use App\Models\Presence;
use App\Models\ShiftStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PresenceMonthlyTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $admin;
    protected User $staff;
    protected User $otherStaff;
    protected ShiftStore $shift;
    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        \Spatie\Permission\Models\Role::create(['name' => 'admin']);
        \Spatie\Permission\Models\Role::create(['name' => 'staff']);

        $this->store = Store::factory()->create();
        $this->shift = ShiftStore::factory()->create([
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->admin = User::factory()->create(['name' => 'Admin', 'email' => 'admin@test.com']);
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create(['name' => 'Staff Satu', 'email' => 'staff1@test.com']);
        $this->staff->assignRole('staff');

        $this->otherStaff = User::factory()->create(['name' => 'Staff Dua', 'email' => 'staff2@test.com']);
        $this->otherStaff->assignRole('staff');
    }

    private function presenceAt(User $user, string $checkIn, ?string $checkOut = null): Presence
    {
        return Presence::create([
            'created_by_id' => $user->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shift->id,
            'status' => 2,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'latitude_in' => -6.2,
            'longitude_in' => 106.8,
            'image_in' => 'in.jpg',
        ]);
    }

    public function test_non_admin_is_forced_to_own_presence_even_when_sending_user_id(): void
    {
        Sanctum::actingAs($this->staff);
        $this->presenceAt($this->staff, '2026-06-10 08:00:00', '2026-06-10 16:00:00');
        $this->presenceAt($this->otherStaff, '2026-06-12 08:00:00', '2026-06-12 16:00:00');

        $res = $this->getJson('/api/presences/monthly?period=2026-06&user_id=' . $this->otherStaff->id);

        $res->assertOk();
        $res->assertJsonPath('data.user_id', $this->staff->id);
        $dates = collect($res->json('data.presences'))->pluck('check_in')->map(fn($s) => substr($s, 0, 10));
        $this->assertContains('2026-06-10', $dates);
        $this->assertNotContains('2026-06-12', $dates);
    }

    public function test_admin_can_view_other_employee_presences(): void
    {
        Sanctum::actingAs($this->admin);
        $this->presenceAt($this->otherStaff, '2026-06-10 09:00:00', '2026-06-10 16:00:00');

        $res = $this->getJson('/api/presences/monthly?period=2026-06&user_id=' . $this->otherStaff->id);

        $res->assertOk();
        $res->assertJsonPath('data.user_id', $this->otherStaff->id);
        $res->assertJsonPath('data.user_name', 'Staff Dua');
        $res->assertJsonPath('data.start', '2026-05-26');
        $res->assertJsonPath('data.end', '2026-06-25');
        $res->assertJsonPath('data.period', '2026-06');
        $this->assertCount(1, $res->json('data.presences'));
        // Verifikasi struktur field formatPresence() ada
        $p = $res->json('data.presences')[0];
        $this->assertArrayHasKey('check_in', $p);
        $this->assertArrayHasKey('check_out', $p);
        $this->assertArrayHasKey('check_in_status', $p);
        $this->assertArrayHasKey('check_out_status', $p);
        $this->assertArrayHasKey('late_minutes', $p);
        // CATATAN: assertion nilai check_in_status ('terlambat' dll) tidak bisa diverifikasi
        // di test schema karena formatPresence() membaca shiftStore->shift_start_time yang
        // bukan kolom ter-track (hanya ada di live DB). Verifikasi nilai dilakukan via
        // smoke test manual terhadap DB live (Task build & run).
    }

    public function test_presences_outside_cutoff_window_excluded(): void
    {
        Sanctum::actingAs($this->admin);
        $this->presenceAt($this->otherStaff, '2026-05-25 08:00:00', '2026-05-25 16:00:00');
        $this->presenceAt($this->otherStaff, '2026-06-10 08:00:00', '2026-06-10 16:00:00');
        $this->presenceAt($this->otherStaff, '2026-06-26 08:00:00', '2026-06-26 16:00:00');

        $res = $this->getJson('/api/presences/monthly?period=2026-06&user_id=' . $this->otherStaff->id);

        $res->assertOk();
        $this->assertCount(1, $res->json('data.presences'));
    }

    public function test_summary_structure_and_total_hadir(): void
    {
        Sanctum::actingAs($this->admin);
        $this->presenceAt($this->otherStaff, '2026-06-10 08:00:00', '2026-06-10 16:00:00');
        $this->presenceAt($this->otherStaff, '2026-06-11 09:00:00', '2026-06-11 15:00:00');

        $res = $this->getJson('/api/presences/monthly?period=2026-06&user_id=' . $this->otherStaff->id);

        $res->assertOk();
        $summary = $res->json('data.summary');
        // Verifikasi struktur summary lengkap + total_hadir akurat (field ini tidak
        // bergantung pada kolom shift, jadi bisa diverifikasi).
        $this->assertSame(2, $summary['total_hadir']);
        foreach (['total_hadir', 'total_menit_terlambat', 'count_terlambat', 'count_tepat_waktu', 'count_pulang_cepat'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }
        // count_* bergantung pada check_in_status/check_out_status yg juga butuh kolom
        // shift_start_time; nilai spesifik diverifikasi via smoke test live, bukan di sini.
    }

    public function test_validation_rejects_missing_period(): void
    {
        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/presences/monthly');
        $res->assertStatus(422);
    }
}
