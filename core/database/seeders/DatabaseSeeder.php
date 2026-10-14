<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Cliente;
use App\Models\Suscripcion;
use App\Models\Server;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Sembrar planes básicos primero para que las suscripciones no fallen
        // Esto normalmente se haría en el PlanSeeder que ya tienen
        // $this->call(PlanSeeder::class); 

        // 1 Admin
        \App\Models\Cliente::firstOrCreate(['email' => 'admin@proservers.com'], [
            'firebase_uid' => 'ADMIN_UUID_123',
            'email' => 'admin@proservers.com',
            'username' => 'admin',
            'nombre' => 'Super',
            'apellido' => 'Admin',
            'password' => Hash::make('admin123'),
            'role' => 'ceo',
        ]);

        // 3 Clientes normales
        $clientes = Cliente::factory(3)->create();

        // 5 Servidores mockeados (Asignados a los clientes)
        foreach (range(1, 5) as $i) {
            $cliente = $clientes->random();
            $suscripcion = Suscripcion::factory()->create([
                'cliente_id' => $cliente->id
            ]);
            Server::factory()->create([
                'suscripcion_id' => $suscripcion->id
            ]);
        }
    }
}