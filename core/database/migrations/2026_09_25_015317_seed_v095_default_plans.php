<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $plans = [
            ['nombre' => 'redstone', 'precio_mensual' => 6, 'memoria_mb' => 4096, 'cpu_cores' => 2, 'almacenamiento_gb' => 20],
            ['nombre' => 'hierro', 'precio_mensual' => 9, 'memoria_mb' => 8192, 'cpu_cores' => 2, 'almacenamiento_gb' => 40],
            ['nombre' => 'cobre', 'precio_mensual' => 12, 'memoria_mb' => 12288, 'cpu_cores' => 3, 'almacenamiento_gb' => 60],
            ['nombre' => 'oro', 'precio_mensual' => 15, 'memoria_mb' => 16384, 'cpu_cores' => 4, 'almacenamiento_gb' => 80],
            ['nombre' => 'diamante', 'precio_mensual' => 25, 'memoria_mb' => 32768, 'cpu_cores' => 6, 'almacenamiento_gb' => 120],
            ['nombre' => 'netherite', 'precio_mensual' => 45, 'memoria_mb' => 65536, 'cpu_cores' => 8, 'almacenamiento_gb' => 200],
            ['nombre' => 'ghost-warrior', 'precio_mensual' => 85, 'memoria_mb' => 131072, 'cpu_cores' => 12, 'almacenamiento_gb' => 400],
        ];

        foreach ($plans as $plan) {
            DB::table('planes')->updateOrInsert(
                ['nombre' => $plan['nombre']],
                [...$plan, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('planes')->whereIn('nombre', [
            'redstone', 'hierro', 'cobre', 'oro', 'diamante', 'netherite', 'ghost-warrior',
        ])->delete();
    }
};
