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
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            // Identificador único y seguro que nos manda Firebase:
            $table->string('firebase_uid')->unique(); 
            
            // Datos personales:
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->string('nombre');
            $table->string('apellido')->nullable();
            $table->string('phone')->nullable();
            
            // Integraciones y compras:
            $table->string('discord_id')->nullable(); // Para vincular roles en tu servidor de Discord
            $table->string('plan_activo')->default('ninguno'); // Ej: redstone, hierro, diamante
            $table->string('role')->default('usuario'); // Ej: usuario, ceo, soporte
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};