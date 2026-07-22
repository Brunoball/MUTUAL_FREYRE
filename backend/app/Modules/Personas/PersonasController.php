<?php
declare(strict_types=1);

namespace App\Modules\Personas;

use App\Modules\Personas\PersonasService;
use App\Core\Request;
use App\Core\Response;

final class PersonasController
{
    public function __construct(private readonly PersonasService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
