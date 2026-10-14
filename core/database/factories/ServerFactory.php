<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Suscripcion;

class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'suscripcion_id' => Suscripcion::factory(),
            'transaction_id' => 'MOCK-' . fake()->randomNumber(5),
            'container_id' => fake()->uuid(),
            'port' => fake()->numberBetween(25565, 26000),
            'status' => 'running',
        ];
    }
}