<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NodeClient;
use App\Services\TestDataPurger;
use Illuminate\Console\Command;

final class PurgeTestData extends Command
{
    protected $signature = 'db:purge-test-data {--force : Aplica el borrado (sin esta opción solo se simula)}';

    protected $description = 'Elimina cuentas y datos de prueba (SQL Server y MongoDB) conservando las cuentas reales';

    public function handle(TestDataPurger $purger, NodeClient $node): int
    {
        $aplicar = (bool) $this->option('force');
        $this->info($aplicar ? 'MODO REAL: se borrarán los datos.' : 'SIMULACRO: no se borra nada (usa --force para aplicar).');

        $sql = $purger->purgarSql($aplicar);
        $this->line("SQL Server: {$this->n(count($sql['clientes']))} clientes, {$sql['suscripciones']} suscripciones, {$sql['servidores']} servidores, {$sql['tickets']} tickets.");
        foreach ($sql['clientes'] as $c) {
            $this->line("  - #{$c['id']} {$c['email']}");
        }

        $r = $node->get('/api/admin/all-servers');
        if (! $r['ok']) {
            $this->error('No se pudo consultar Docker: se omite MongoDB para no borrar de más.');

            return self::FAILURE;
        }
        $mongo = $purger->purgarMongo($aplicar, collect($r['data']['servers'] ?? [])->pluck('id')->all(), array_map(fn ($c) => (string) $c['id'], $sql['clientes']));
        $this->line('MongoDB: ' . count($mongo) . ' eventos huérfanos.');
        foreach ($mongo as $e) {
            $this->line("  - {$e['event']} ({$e['motivo']})");
        }

        return self::SUCCESS;
    }

    private function n(int $n): string
    {
        return (string) $n;
    }
}
