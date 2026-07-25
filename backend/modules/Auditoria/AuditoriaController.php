<?php
declare(strict_types=1);

namespace App\Modules\Auditoria;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;

final class AuditoriaController
{
    public function __construct(private readonly AuditoriaService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->index([
            'buscar' => $request->query('buscar', ''),
            'modulo' => $request->query('modulo', ''),
            'accion' => $request->query('accion', ''),
            'resultado' => $request->query('resultado', ''),
            'entidad' => $request->query('entidad', ''),
            'usuario' => $request->query('usuario', ''),
            'desde' => $request->query('desde', ''),
            'hasta' => $request->query('hasta', ''),
            'pagina' => $request->query('pagina', 1),
            'limite' => $request->query('limite', 50),
        ]));
    }

    public function detail(Request $request, array $session, string $correlationId): never
    {
        $id = filter_var($request->query('id'), FILTER_VALIDATE_INT);
        if (!$id) {
            throw new ApiException('Indicá un evento válido.', 'INVALID_AUDIT_EVENT_ID', 422);
        }
        Response::success($this->service->detail((int)$id));
    }

    public function integrity(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->integrity());
    }
}
