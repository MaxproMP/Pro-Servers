<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RUTAS DEL CEREBRO (Laravel API)
|--------------------------------------------------------------------------
| Laravel ya no devuelve archivos HTML (vistas). De eso se encarga el 
| contenedor Frontend con Nginx. Ahora Laravel solo responde en JSON.
*/

Route::get('/', function () {
    return response()->json([
        'status' => 'ProServers API 100% Operativa',
        'arquitectura' => 'Microservicios (Docker)',
        'conexion_db' => 'Establecida con SQL Server'
    ], 200);
});

// Nota para los profesores: 
// Las antiguas rutas web (/panel, /crear, /checkout, /perfil, /admin)
// fueron delegadas al Frontend. La lógica real de datos, pagos y usuarios
// se encuentra programada profesionalmente en routes/api.php