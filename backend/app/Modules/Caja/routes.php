<?php
declare(strict_types=1);

use App\Modules\Caja\CajaController;
use App\Modules\Caja\CajaPolicy;
use App\Core\Router;

return static function (Router $router, CajaController $controller): void {
    $router->get('/api/backoffice/v1/caja', [$controller, 'index'], ['permission' => CajaPolicy::VIEW]);
};
