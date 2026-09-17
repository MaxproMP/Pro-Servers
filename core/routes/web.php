<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RUTAS PÚBLICAS (Visitantes)
|--------------------------------------------------------------------------
| Accesibles para todo el mundo. Landing page y catálogo.
*/
Route::get('/', function () {
    return view('welcome');
});

Route::get('/planes', function () {
    return view('planes');
});

/*
|--------------------------------------------------------------------------
| RUTAS DE CLIENTES (Zona Privada)
|--------------------------------------------------------------------------
| En la V1.0 estarán protegidas por un Middleware personalizado que 
| validará el Token JWT de Firebase desde el Backend.
| (Ej: ->middleware('auth.firebase'))
*/
Route::prefix('/')->group(function () {
    
    Route::get('/panel', function () {
        return view('panel');
    });

    Route::get('/crear', function () {
        return view('crear');
    });

    Route::get('/checkout', function () {
        return view('checkout');
    });

    Route::get('/perfil', function () {
        return view('perfil');
    });

});

/*
|--------------------------------------------------------------------------
| RUTAS DE ADMINISTRACIÓN Y STAFF (Alto Nivel)
|--------------------------------------------------------------------------
| Área restringida. Futuro middleware de roles.
| (Ej: ->middleware('role:ceo,soporte'))
*/
Route::prefix('/')->group(function () {

    Route::get('/admin', function () {
        return view('admin');
    });

});