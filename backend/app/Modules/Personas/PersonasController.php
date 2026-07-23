<?php
declare(strict_types=1);

namespace App\Modules\Personas;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;

final class PersonasController
{
    public function __construct(private readonly PersonasService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->index([
            'buscar' => $request->query('buscar', ''),
            'estado' => $request->query('estado', 'ACTIVAS'),
            'tipo' => $request->query('tipo', ''),
            'id_localidad' => $request->query('id_localidad', ''),
            'asociado' => $request->query('asociado', ''),
            'limite' => $request->query('limite', 250),
        ]));
    }

    public function catalogs(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->catalogs());
    }

    public function detail(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->detail($this->personId($request)));
    }

    public function linkImpact(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->linkImpact($this->personId($request)));
    }

    public function create(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->create($request->json(), $session, $correlationId), 201);
    }

    public function update(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->update(
            $this->personId($request),
            $request->json(),
            $session,
            $correlationId
        ));
    }

    public function changeStatus(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->changeStatus(
            $this->personId($request),
            $request->json(),
            $session,
            $correlationId
        ));
    }

    private function personId(Request $request): int
    {
        $id = filter_var($request->query('id'), FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new ApiException('Indicá una persona válida.', 'INVALID_PERSON_ID', 422);
        }
        return (int)$id;
    }
}
