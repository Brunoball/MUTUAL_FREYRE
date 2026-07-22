<?php
declare(strict_types=1);

namespace App\Modules\Auditoria;

use App\Modules\Auditoria\AuditoriaService;
use App\Core\Request;
use App\Core\Response;

final class AuditoriaController
{
    public function __construct(private readonly AuditoriaService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
