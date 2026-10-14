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
        Schema::create('tickets_soporte', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('estado')->default('abierto');
            $table->timestamps();
            $table->index(['cliente_id', 'estado']);
        });

        Schema::create('mensajes_ticket', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets_soporte')->cascadeOnDelete();
            $table->string('remitente'); // user | admin | ai
            $table->text('mensaje');
            $table->text('log_analizado')->nullable(); // extracto de log analizado por Mine AI
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE tickets_soporte ADD CONSTRAINT tickets_soporte_estado_check CHECK (estado in ('abierto', 'cerrado'))");
            DB::statement("ALTER TABLE mensajes_ticket ADD CONSTRAINT mensajes_ticket_remitente_check CHECK (remitente in ('user', 'admin', 'ai'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mensajes_ticket');
        Schema::dropIfExists('tickets_soporte');
    }
};
