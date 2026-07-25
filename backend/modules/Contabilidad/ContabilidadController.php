<?php
declare(strict_types=1);

namespace App\Modules\Contabilidad;

use App\Modules\Contabilidad\ContabilidadService;
use App\Core\Request;
use App\Core\Response;

final class ContabilidadController
{
    public function __construct(private readonly ContabilidadService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
