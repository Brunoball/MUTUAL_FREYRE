<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;

final class AyudasController
{
    public function __construct(private readonly AyudasService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->index([
            'buscar' => $request->query('buscar', ''),
            'tipo' => $request->query('tipo', ''),
            'estado' => $request->query('estado', 'VIGENTES'),
            'limite' => $request->query('limite', 250),
        ]));
    }

    public function catalogs(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->catalogs((string)$request->query('fecha', date('Y-m-d'))));
    }

    public function parameters(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->parameters());
    }

    public function currentBnaQuote(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->currentBnaQuote());
    }

    public function detail(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->detail($this->aidId($request)));
    }

    public function simulate(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->simulate($request->json()));
    }

    public function create(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->create($request->json(), $session, $correlationId), 201);
    }

    public function renew(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->renew(
            $this->aidId($request),
            $request->json(),
            $session,
            $correlationId
        ), 201);
    }

    public function annul(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->annul(
            $this->aidId($request),
            $request->json(),
            $session,
            $correlationId
        ));
    }

    public function saveRate(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->saveRate($request->json(), $session, $correlationId), 201);
    }

    public function saveQuote(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->saveQuote($request->json(), $session, $correlationId));
    }

    private function aidId(Request $request): int
    {
        $id = filter_var($request->query('id'), FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new ApiException('Indicá una ayuda económica válida.', 'INVALID_AID_ID', 422);
        }
        return (int)$id;
    }
}
