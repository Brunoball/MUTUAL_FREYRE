<?php
declare(strict_types=1);

use App\Modules\Cobranzas\CobranzasController;
use App\Modules\Cobranzas\CobranzasPolicy;
use App\Core\Router;

return static function (Router $router, CobranzasController $controller): void {
    $router->get('/api/backoffice/v1/cobranzas', [$controller, 'index'], ['permission' => CobranzasPolicy::VIEW]);
};
