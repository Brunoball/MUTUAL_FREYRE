<?php
declare(strict_types=1);

namespace App\Modules\Bancos;

use App\Modules\Bancos\BancosService;
use App\Core\Request;
use App\Core\Response;

final class BancosController
{
    public function __construct(private readonly BancosService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
