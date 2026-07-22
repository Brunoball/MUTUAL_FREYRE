<?php
declare(strict_types=1);

use App\Modules\Valores\ValoresController;
use App\Modules\Valores\ValoresPolicy;
use App\Core\Router;

return static function (Router $router, ValoresController $controller): void {
    $router->get('/api/backoffice/v1/valores', [$controller, 'index'], ['permission' => ValoresPolicy::VIEW]);
};
