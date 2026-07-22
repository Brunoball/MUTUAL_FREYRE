<?php
declare(strict_types=1);

use App\Modules\Cuentas\CuentasController;
use App\Modules\Cuentas\CuentasPolicy;
use App\Core\Router;

return static function (Router $router, CuentasController $controller): void {
    $router->get('/api/backoffice/v1/cuentas', [$controller, 'index'], ['permission' => CuentasPolicy::VIEW]);
};
