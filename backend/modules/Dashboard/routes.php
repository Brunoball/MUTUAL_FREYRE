<?php
declare(strict_types=1);

use App\Modules\Dashboard\DashboardController;
use App\Modules\Dashboard\DashboardPolicy;
use App\Core\Router;

return static function (Router $router, DashboardController $controller): void {
    $router->get('/api/backoffice/v1/dashboard', [$controller, 'index'], ['permission' => DashboardPolicy::VIEW]);
};
