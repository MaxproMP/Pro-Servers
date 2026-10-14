<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('estado')->default('activo'); // activo | congelado (Zona de Hielo)
        });

        Schema::table('planes', function (Blueprint $table): void {
            $table->boolean('visible')->default(true);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE clientes ADD CONSTRAINT clientes_estado_check CHECK (estado in ('activo', 'congelado'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE clientes DROP CONSTRAINT clientes_estado_check');
        }
        Schema::table('planes', fn (Blueprint $t) => $t->dropColumn('visible'));
        Schema::table('clientes', fn (Blueprint $t) => $t->dropColumn('estado'));
    }
};
