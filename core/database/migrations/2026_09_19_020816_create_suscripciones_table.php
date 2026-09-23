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
        Schema::create('suscripciones', function (Blueprint $table) {
            $table->id();
            
            // Claves foráneas (Relaciones con users y planes)
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('plan_id')->nullable()->constrained('planes')->onDelete('set null');
            
            // Campos de facturación y webhook
            $table->string('firebase_uid')->nullable();
            $table->string('plan_nombre')->nullable();
            $table->string('estado')->default('pendiente'); // 'pendiente', 'activo'
            $table->integer('ciclo_meses')->default(1);
            
            // Fechas de control
            $table->timestamp('fecha_inicio')->nullable();
            $table->timestamp('fecha_vencimiento')->nullable();
            
            $table->softDeletes();
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