<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad del chat de soporte: quién del staff respondió cada mensaje y cuándo estuvo activo por última vez.
 * autor_id usa NO ACTION: SQL Server rechaza un segundo camino en cascada hacia clientes
 * (mensajes_ticket → tickets_soporte → clientes ya es CASCADE) y clientes usa borrado lógico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mensajes_ticket', function (Blueprint $table): void {
            $table->foreignId('autor_id')->nullable()->constrained('clientes')->noActionOnDelete();
        });

        Schema::table('clientes', function (Blueprint $table): void {
            $table->dateTime('ultima_actividad')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mensajes_ticket', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('autor_id');
        });
        Schema::table('clientes', fn (Blueprint $t) => $t->dropColumn('ultima_actividad'));
    }
};
