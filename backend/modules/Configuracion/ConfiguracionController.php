<?php
declare(strict_types=1);

namespace App\Modules\Configuracion;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;

final class ConfiguracionController
{
    public function __construct(private readonly ConfiguracionService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->overview($session));
    }

    public function users(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->users($session));
    }

    public function createUser(Request $request, array $session, string $correlationId): never
    {
        Response::success(
            $this->service->createUser($request->json(), $session, $correlationId),
            201
        );
    }

    public function updateUser(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->updateUser(
            $this->accessId($request),
            $request->json(),
            $session,
            $correlationId
        ));
    }

    public function changeStatus(Request $request, array $session, string $correlationId): never
    {
        $input = $request->json();
        if (!array_key_exists('activo', $input)) {
            throw new ApiException(
                'Indicá el estado del usuario.',
                'VALIDATION_ERROR',
                422,
                ['activo' => 'Obligatorio']
            );
        }

        Response::success($this->service->changeStatus(
            $this->accessId($request),
            filter_var($input['activo'], FILTER_VALIDATE_BOOL),
            $session,
            $correlationId
        ));
    }

    public function deleteUser(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->deleteUser(
            $this->accessId($request),
            $session,
            $correlationId
        ));
    }

    private function accessId(Request $request): int
    {
        $id = filter_var($request->query('id'), FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new ApiException(
                'Indicá un usuario válido.',
                'INVALID_USER_ACCESS_ID',
                422
            );
        }
        return (int)$id;
    }
}
