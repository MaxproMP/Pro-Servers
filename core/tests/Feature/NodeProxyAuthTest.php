<?php

use App\Models\Cliente;
use Illuminate\Support\Facades\Http;

it('reenvía el token de Firebase al demonio Node', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $cliente = new Cliente(['firebase_uid' => 'uid-test']);

    $this->withoutMiddleware(\App\Http\Middleware\FirebaseTokenMiddleware::class)
        ->actingAs($cliente)
        ->postJson('/api/project/create', ['edition' => 'java'], ['Authorization' => 'Bearer token-abc'])
        ->assertOk();

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer token-abc')
        && $r['uid'] === 'uid-test');
});
