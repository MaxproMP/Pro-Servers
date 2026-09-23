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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            
            // Relaciones
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('firebase_uid')->nullable();
            
            // Datos del Servidor / Plan
            $table->string('plan_nombre')->nullable();
            
            // Datos Técnicos (Docker & Facturación)
            $table->string('transaction_id')->nullable(); 
            $table->string('container_id')->nullable();   
            $table->integer('port')->nullable();          
            
            // Estado del contenedor
            $table->string('status')->default('pending'); 
            
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