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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();

            // Un servidor pertenece a una suscripción y hereda sus límites.
            $table->foreignId('suscripcion_id')->unique()->constrained('suscripciones')->onDelete('cascade');

            // Datos Técnicos (Docker & Facturación)
            $table->string('transaction_id')->nullable();
            $table->string('container_id')->nullable();
            $table->integer('port')->nullable();

            // Estado del contenedor
            $table->string('status')->default('pending');
            $table->check('port is null or (port between 1024 and 65535)');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
