<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'nickname' => $this->faker->companySuffix(),
            // Skema kanonik (services/migration) menuntut email NOT NULL.
            'email' => $this->faker->unique()->safeEmail(),
            // Skema kanonik juga menuntut status NOT NULL tanpa default.
            'status' => 1,
            'address' => $this->faker->address(),
            'latitude' => $this->faker->latitude(-90, 90),
            'longitude' => $this->faker->longitude(-180, 180),
            'radius' => $this->faker->numberBetween(50, 200),
            'is_active' => true,
        ];
    }
}
