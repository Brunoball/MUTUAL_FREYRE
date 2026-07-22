<?php
declare(strict_types=1);

use App\Modules\Ahorros\AhorrosController;
use App\Modules\Ahorros\AhorrosPolicy;
use App\Core\Router;

return static function (Router $router, AhorrosController $controller): void {
    $router->get('/api/backoffice/v1/ahorros', [$controller, 'index'], ['permission' => AhorrosPolicy::VIEW]);
};
