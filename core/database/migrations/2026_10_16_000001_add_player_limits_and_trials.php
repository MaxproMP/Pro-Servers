<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Escala comercial v2: precio, RAM, vCPU y jugadores máximos (null = ilimitado). */
    private const ESCALA = [
        'redstone' => [6, 8192, 2, 4],
        'hierro' => [9, 12288, 2, 10],
        'cobre' => [12, 16384, 3, 20],
        'oro' => [15, 32768, 4, 50],
        'diamante' => [25, 65536, 6, 100],
        'netherite' => [45, 131072, 8, 200],
        'ghost-warrior' => [85, 262144, 12, null],
    ];

    public function up(): void
    {
        Schema::table('planes', function (Blueprint $table): void {
            $table->unsignedInteger('max_jugadores')->nullable();
        });

        Schema::table('suscripciones', function (Blueprint $table): void {
            $table->dateTime('trial_ends_at')->nullable();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE planes ADD CONSTRAINT planes_max_jugadores_check CHECK (max_jugadores IS NULL OR max_jugadores > 0)');
        }

        foreach (self::ESCALA as $nombre => [$precio, $ram, $cpu, $jugadores]) {
            DB::table('planes')->where('nombre', $nombre)->update([
                'precio_mensual' => $precio,
                'memoria_mb' => $ram,
                'cpu_cores' => $cpu,
                'max_jugadores' => $jugadores,
            ]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE planes DROP CONSTRAINT planes_max_jugadores_check');
        }
        Schema::table('suscripciones', fn (Blueprint $t) => $t->dropColumn('trial_ends_at'));
        Schema::table('planes', fn (Blueprint $t) => $t->dropColumn('max_jugadores'));
    }
};
