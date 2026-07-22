<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Auth\AuthService;
use App\Core\Request;
use App\Core\Response;

final class AuthController
{
    public function __construct(private readonly AuthService $service) {}

    public function login(Request $request, ?array $session, string $correlationId): never
    {
        Response::success($this->service->login($request->json(), $correlationId), 200);
    }

    public function current(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->current($session));
    }

    public function logout(Request $request, array $session, string $correlationId): never
    {
        $this->service->logout($session, $correlationId);
        Response::success([]);
    }
}
