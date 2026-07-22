<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Modules\Ayudas\AyudasService;
use App\Core\Request;
use App\Core\Response;

final class AyudasController
{
    public function __construct(private readonly AyudasService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
