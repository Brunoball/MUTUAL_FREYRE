<?php
declare(strict_types=1);

use App\Modules\Bancos\BancosController;
use App\Modules\Bancos\BancosPolicy;
use App\Core\Router;

return static function (Router $router, BancosController $controller): void {
    $router->get('/api/backoffice/v1/bancos', [$controller, 'index'], ['permission' => BancosPolicy::VIEW]);
};
