<?php

test('la ruta principal devuelve el estado de la API en JSON', function () {
    $response = $this->get('/');

    $response->assertStatus(200)
             ->assertJson([
                 'status' => 'ProServers API 100% Operativa',
                 'arquitectura' => 'Microservicios (Docker)'
             ]);
});