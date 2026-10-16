<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Services\AuditLogService;
use App\Services\NodeClient;
use Illuminate\Support\Facades\Auth;

/** Base de los controladores del Panel Admin: auditoría en MongoDB y acceso al demonio. */
abstract class AdminController extends Controller
{
    public function __construct(protected NodeClient $node, protected AuditLogService $audit)
    {
    }

    protected function actor(): Cliente
    {
        return Auth::user();
    }

    protected function esCeo(): bool
    {
        return $this->actor()->role === 'ceo';
    }

    /** Registra la acción del staff; un fallo de MongoDB nunca debe romper la operación. */
    protected function auditar(string $evento, ?string $serverId = null, array $payload = []): void
    {
        try {
            $this->audit->record($evento, 'staff', (string) $this->actor()->id, $serverId, $payload + ['rol' => $this->actor()->role]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
