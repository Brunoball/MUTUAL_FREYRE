<?php
declare(strict_types=1);

namespace App\Modules\Cobranzas;

use App\Modules\Cobranzas\CobranzasService;
use App\Core\Request;
use App\Core\Response;

final class CobranzasController
{
    public function __construct(private readonly CobranzasService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
