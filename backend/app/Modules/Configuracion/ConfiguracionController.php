<?php
declare(strict_types=1);

namespace App\Modules\Configuracion;

use App\Modules\Configuracion\ConfiguracionService;
use App\Core\Request;
use App\Core\Response;

final class ConfiguracionController
{
    public function __construct(private readonly ConfiguracionService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
