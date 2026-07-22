<?php
declare(strict_types=1);

namespace App\Modules\Ahorros;

use App\Modules\Ahorros\AhorrosService;
use App\Core\Request;
use App\Core\Response;

final class AhorrosController
{
    public function __construct(private readonly AhorrosService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
