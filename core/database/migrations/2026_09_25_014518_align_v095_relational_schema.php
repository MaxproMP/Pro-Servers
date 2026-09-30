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

        Schema::table('suscripciones', function (Blueprint $table): void {
            if (! Schema::hasColumn('suscripciones', 'cliente_id')) {
                $table->unsignedBigInteger('cliente_id')->nullable();
            }
        });

        DB::statement("UPDATE s SET cliente_id = c.id FROM suscripciones s INNER JOIN clientes c ON c.firebase_uid = s.firebase_uid WHERE s.cliente_id IS NULL");
        DB::statement("ALTER TABLE suscripciones DROP CONSTRAINT IF EXISTS suscripciones_user_id_foreign");
        DB::statement("ALTER TABLE suscripciones DROP CONSTRAINT IF EXISTS suscripciones_plan_id_foreign");
        DB::statement("ALTER TABLE suscripciones DROP CONSTRAINT IF EXISTS suscripciones_cliente_id_foreign");
        DB::statement("ALTER TABLE suscripciones ALTER COLUMN cliente_id BIGINT NOT NULL");
        DB::statement("ALTER TABLE suscripciones ALTER COLUMN plan_id BIGINT NOT NULL");
        DB::statement("ALTER TABLE suscripciones ADD CONSTRAINT suscripciones_cliente_id_foreign FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE NO ACTION");
        DB::statement("ALTER TABLE suscripciones ADD CONSTRAINT suscripciones_plan_id_foreign FOREIGN KEY (plan_id) REFERENCES planes(id) ON DELETE NO ACTION");
        DB::statement("ALTER TABLE suscripciones ADD CONSTRAINT suscripciones_ciclo_meses_check CHECK (ciclo_meses > 0)");

        foreach (['user_id', 'firebase_uid', 'plan_nombre'] as $column) {
            if (Schema::hasColumn('suscripciones', $column)) {
                Schema::table('suscripciones', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        Schema::table('pagos', function (Blueprint $table): void {
            if (! Schema::hasColumn('pagos', 'pasarela_pago')) {
                $table->string('pasarela_pago')->nullable();
            }
            if (! Schema::hasColumn('pagos', 'medio_pago')) {
                $table->string('medio_pago')->nullable();
            }
        });

        DB::statement("UPDATE pagos SET pasarela_pago = COALESCE(metodo, 'desconocida'), medio_pago = 'no_definido' WHERE pasarela_pago IS NULL OR medio_pago IS NULL");
        DB::statement("ALTER TABLE pagos DROP CONSTRAINT IF EXISTS pagos_suscripcion_id_foreign");
        DB::statement("ALTER TABLE pagos ALTER COLUMN suscripcion_id BIGINT NOT NULL");
        DB::statement("ALTER TABLE pagos ALTER COLUMN monto DECIMAL(10,2) NOT NULL");
        DB::statement("ALTER TABLE pagos ALTER COLUMN pasarela_pago NVARCHAR(255) NOT NULL");
        DB::statement("ALTER TABLE pagos ALTER COLUMN medio_pago NVARCHAR(255) NOT NULL");
        DB::statement("ALTER TABLE pagos ADD CONSTRAINT pagos_suscripcion_id_foreign FOREIGN KEY (suscripcion_id) REFERENCES suscripciones(id) ON DELETE NO ACTION");
        DB::statement("ALTER TABLE pagos ADD CONSTRAINT pagos_monto_check CHECK (monto > 0)");

        foreach (['firebase_uid', 'metodo'] as $column) {
            if (Schema::hasColumn('pagos', $column)) {
                Schema::table('pagos', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        Schema::table('servers', function (Blueprint $table): void {
            if (! Schema::hasColumn('servers', 'suscripcion_id')) {
                $table->unsignedBigInteger('suscripcion_id')->nullable();
            }
        });

        DB::statement("ALTER TABLE servers DROP CONSTRAINT IF EXISTS servers_user_id_foreign");
        DB::statement("ALTER TABLE servers ALTER COLUMN suscripcion_id BIGINT NOT NULL");
        DB::statement("ALTER TABLE servers ADD CONSTRAINT servers_suscripcion_id_foreign FOREIGN KEY (suscripcion_id) REFERENCES suscripciones(id) ON DELETE CASCADE");
        DB::statement("CREATE UNIQUE INDEX servers_suscripcion_id_unique ON servers(suscripcion_id)");
        DB::statement("ALTER TABLE servers ADD CONSTRAINT servers_port_check CHECK (port IS NULL OR port BETWEEN 1024 AND 65535)");

        foreach (['user_id', 'firebase_uid', 'plan_nombre'] as $column) {
            if (Schema::hasColumn('servers', $column)) {
                Schema::table('servers', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('La migración de alineación v0.9.5 no es reversible automáticamente.');
    }
};
