<?php
declare(strict_types=1);

use App\Modules\Auditoria\AuditoriaController;
use App\Modules\Auditoria\AuditoriaPolicy;
use App\Core\Router;

return static function (Router $router, AuditoriaController $controller): void {
    $router->get('/api/backoffice/v1/auditoria', [$controller, 'index'], ['permission' => AuditoriaPolicy::VIEW]);
    $router->get('/api/backoffice/v1/auditoria/detalle', [$controller, 'detail'], ['permission' => AuditoriaPolicy::VIEW]);
    $router->get('/api/backoffice/v1/auditoria/integridad', [$controller, 'integrity'], ['permission' => AuditoriaPolicy::VIEW]);
};
