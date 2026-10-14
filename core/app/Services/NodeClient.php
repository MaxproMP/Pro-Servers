<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Cliente del demonio Node (red interna Docker), autenticado con el secreto compartido. */
final class NodeClient
{
    private const BASE = 'http://minecraft-panel-backend:3000';

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function get(string $path, array $query = [], int $timeout = 20): array
    {
        return $this->send(fn (PendingRequest $h) => $h->timeout($timeout)->get(self::BASE . $path, $query));
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function post(string $path, array $data = [], int $timeout = 25): array
    {
        return $this->send(fn (PendingRequest $h) => $h->timeout($timeout)->post(self::BASE . $path, $data));
    }

    private function send(callable $call): array
    {
        $http = Http::acceptJson()->withHeaders(['x-daemon-secret' => (string) config('services.daemon.secret')]);

        try {
            $r = $call($http);

            return ['ok' => $r->successful(), 'status' => $r->status(), 'data' => (array) $r->json()];
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => 502, 'data' => ['error' => 'El demonio Node no responde.']];
        }
    }
}
