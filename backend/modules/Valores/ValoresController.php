<?php
declare(strict_types=1);

namespace App\Modules\Valores;

use App\Modules\Valores\ValoresService;
use App\Core\Request;
use App\Core\Response;

final class ValoresController
{
    public function __construct(private readonly ValoresService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
