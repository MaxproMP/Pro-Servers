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
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();

            // Relación con Suscripciones
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->onDelete('restrict');

            // Campos de facturación
            $table->decimal('monto', 10, 2);
            $table->string('pasarela_pago');
            $table->string('medio_pago');
            $table->string('transaccion_id')->unique()->nullable();
            $table->string('estado')->default('pendiente');
            $table->check('monto > 0');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
