<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class ClienteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'firebase_uid' => fake()->uuid(),
            'email' => fake()->unique()->safeEmail(),
            'username' => fake()->unique()->userName(),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'password' => Hash::make('password'),
            'role' => 'usuario',
            'plan_activo' => 'ninguno',
        ];
    }
}