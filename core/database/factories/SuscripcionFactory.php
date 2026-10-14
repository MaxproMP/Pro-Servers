<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Cliente;
use App\Models\Plan;

class SuscripcionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'plan_id' => Plan::inRandomOrder()->first()->id ?? 1,
            'ciclo_meses' => 1,
            'estado' => 'activa',
        ];
    }
}