<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Entorno de pruebas que debe ganar siempre al del contenedor (que apunta a la base real). */
    private const ENTORNO = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ];

    public function createApplication()
    {
        foreach (self::ENTORNO as $clave => $valor) {
            putenv("$clave=$valor");
            $_ENV[$clave] = $_SERVER[$clave] = $valor;
        }

        $app = parent::createApplication();

        // Salvaguarda: RefreshDatabase borra tablas, así que jamás puede tocar la base real.
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Las pruebas solo pueden ejecutarse con SQLite en memoria. Conexión activa: ' . $app['config']->get('database.default'));
        }

        return $app;
    }
}
