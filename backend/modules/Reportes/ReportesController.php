<?php
declare(strict_types=1);

namespace App\Modules\Reportes;

use App\Modules\Reportes\ReportesService;
use App\Core\Request;
use App\Core\Response;

final class ReportesController
{
    public function __construct(private readonly ReportesService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
