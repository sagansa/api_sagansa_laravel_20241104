<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShiftStore>
 */
class ShiftStoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startTime = $this->faker->time('H:i:s', '10:00:00');
        $endTime = $this->faker->time('H:i:s', '18:00:00');
        
        return [
            'store_id' => \App\Models\Store::factory(),
            'name' => $this->faker->randomElement(['Morning Shift', 'Day Shift', 'Evening Shift', 'Night Shift']),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'tolerance_minutes' => $this->faker->numberBetween(5, 30),
            'is_active' => true,
        ];
    }
}
