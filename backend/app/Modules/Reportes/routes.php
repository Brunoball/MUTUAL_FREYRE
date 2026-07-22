<?php
declare(strict_types=1);

use App\Modules\Reportes\ReportesController;
use App\Modules\Reportes\ReportesPolicy;
use App\Core\Router;

return static function (Router $router, ReportesController $controller): void {
    $router->get('/api/backoffice/v1/reportes', [$controller, 'index'], ['permission' => ReportesPolicy::VIEW]);
};
