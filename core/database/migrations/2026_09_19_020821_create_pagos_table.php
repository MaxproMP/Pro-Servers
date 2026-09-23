<?php

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
            $table->foreignId('suscripcion_id')->nullable()->constrained('suscripciones')->onDelete('set null');
            
            // Campos de facturación
            $table->string('firebase_uid')->nullable();
            $table->decimal('monto', 10, 2);
            $table->string('metodo')->nullable();
            $table->string('transaccion_id')->unique()->nullable();
            $table->string('estado')->default('pendiente');
            
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