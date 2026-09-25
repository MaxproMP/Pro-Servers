<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('suscripciones', function (Blueprint $table) {
            $table->id();

            // Jerarquía canónica: clientes -> suscripciones -> servidores.
            $table->foreignId('cliente_id')->constrained('clientes')->onDelete('restrict');
            $table->foreignId('plan_id')->constrained('planes')->onDelete('restrict');

            $table->string('estado')->default('pendiente'); // 'pendiente', 'activo'
            $table->integer('ciclo_meses')->default(1);

            // Fechas de control
            $table->timestamp('fecha_inicio')->nullable();
            $table->timestamp('fecha_vencimiento')->nullable();

            $table->softDeletes();
            $table->check("estado in ('pendiente', 'activa', 'suspendida', 'cancelada')");
            $table->check('ciclo_meses > 0');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suscripciones');
    }
};
