<?php

namespace Tests\Unit;

use App\Models\Presence;
use App\Models\ShiftStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;
use Tests\TestCase;

class PresenceCalcTest extends TestCase
{
    use RefreshDatabase;

    private function makeShift(string $start, string $end, int $duration = 8): ShiftStore
    {
        return ShiftStore::factory()->create([
            'shift_start_time' => $start,
            'shift_end_time' => $end,
            'duration' => $duration,
        ]);
    }

    public function test_get_check_out_status_returns_tepat_waktu_when_checkout_equals_shift_end(): void
    {
        $shift = $this->makeShift('08:00:00', '16:00:00');
        $presence = Presence::factory()->create([
            'shift_store_id' => $shift->id,
            'store_id' => Store::factory()->create()->id,
            'check_in' => '2026-06-10 08:00:00',
            'check_out' => '2026-06-10 16:00:00',
        ]);

        $this->assertSame('Tepat Waktu', $presence->getCheckOutStatus());
    }

    public function test_get_check_out_status_returns_cepat_pulang_when_checkout_before_shift_end(): void
    {
        $shift = $this->makeShift('08:00:00', '16:00:00');
        $presence = Presence::factory()->create([
            'shift_store_id' => $shift->id,
            'store_id' => Store::factory()->create()->id,
            'check_in' => '2026-06-10 08:00:00',
            'check_out' => '2026-06-10 14:00:00',
        ]);

        $this->assertSame('Cepat Pulang', $presence->getCheckOutStatus());
    }

    public function test_get_check_out_status_returns_tidak_absen_pulang_when_checkout_null(): void
    {
        $shift = $this->makeShift('08:00:00', '16:00:00');
        $presence = Presence::factory()->create([
            'shift_store_id' => $shift->id,
            'store_id' => Store::factory()->create()->id,
            'check_in' => '2026-06-10 08:00:00',
            'check_out' => null,
        ]);

        $this->assertSame('Tidak Absen Pulang', $presence->getCheckOutStatus());
    }

    public function test_calculate_effective_working_time_subtracts_total_penalty(): void
    {
        $shift = $this->makeShift('08:00:00', '16:00:00', 8);
        $presence = Presence::factory()->create([
            'shift_store_id' => $shift->id,
            'store_id' => Store::factory()->create()->id,
            'check_in' => '2026-06-10 09:00:00',
            'check_out' => '2026-06-10 16:00:00',
        ]);

        $this->assertSame(1, $presence->calculateLateHours());
        $this->assertSame(0, $presence->calculateCheckOutPenalty());
        $this->assertSame(7, $presence->calculateEffectiveWorkingTime());
    }

    public function test_calculate_effective_working_time_floored_at_zero(): void
    {
        $shift = $this->makeShift('08:00:00', '16:00:00', 2);
        $presence = Presence::factory()->create([
            'shift_store_id' => $shift->id,
            'store_id' => Store::factory()->create()->id,
            'check_in' => null,
            'check_out' => null,
        ]);

        $this->assertSame(0, $presence->calculateEffectiveWorkingTime());
    }
}
