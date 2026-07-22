<?php
declare(strict_types=1);

namespace App\Modules\Cuentas;

use App\Modules\Cuentas\CuentasService;
use App\Core\Request;
use App\Core\Response;

final class CuentasController
{
    public function __construct(private readonly CuentasService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
