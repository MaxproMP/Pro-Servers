<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('clientes', 'deleted_at')) {
            Schema::table('clientes', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('pagos', 'fecha_transaccion')) {
            Schema::table('pagos', function (Blueprint $table): void {
                $table->dateTime('fecha_transaccion')->nullable()->index();
            });
        }

        DB::statement('UPDATE pagos SET fecha_transaccion = COALESCE(fecha_transaccion, created_at) WHERE fecha_transaccion IS NULL');

        if (! Schema::hasTable('auditoria_reembolsos')) {
            Schema::create('auditoria_reembolsos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('pago_id');
                $table->foreignId('cliente_id');
                $table->string('motivo_solicitud', 500);
                $table->decimal('monto_devuelto', 10, 2);
                $table->string('estado_auditoria', 40)->default('solicitado');
                $table->timestamps();
            });

            DB::statement('ALTER TABLE auditoria_reembolsos ADD CONSTRAINT auditoria_reembolsos_pago_fk FOREIGN KEY (pago_id) REFERENCES pagos(id) ON DELETE NO ACTION');
            DB::statement('ALTER TABLE auditoria_reembolsos ADD CONSTRAINT auditoria_reembolsos_cliente_fk FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE NO ACTION');
            DB::statement('ALTER TABLE auditoria_reembolsos ADD CONSTRAINT auditoria_reembolsos_monto_check CHECK (monto_devuelto > 0)');
            DB::statement("ALTER TABLE auditoria_reembolsos ADD CONSTRAINT auditoria_reembolsos_estado_check CHECK (estado_auditoria in ('solicitado', 'procesado', 'rechazado', 'fallido'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditoria_reembolsos');

        if (Schema::hasColumn('pagos', 'fecha_transaccion')) {
            Schema::table('pagos', function (Blueprint $table): void {
                $table->dropColumn('fecha_transaccion');
            });
        }
    }
};
