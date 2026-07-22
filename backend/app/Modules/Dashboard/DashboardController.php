<?php
declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Modules\Dashboard\DashboardService;
use App\Core\Request;
use App\Core\Response;

final class DashboardController
{
    public function __construct(private readonly DashboardService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
