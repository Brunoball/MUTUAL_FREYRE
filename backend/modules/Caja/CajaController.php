<?php
declare(strict_types=1);

namespace App\Modules\Caja;

use App\Modules\Caja\CajaService;
use App\Core\Request;
use App\Core\Response;

final class CajaController
{
    public function __construct(private readonly CajaService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
