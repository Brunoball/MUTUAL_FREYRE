<?php
declare(strict_types=1);

use App\Modules\Ayudas\AyudasController;
use App\Modules\Ayudas\AyudasPolicy;
use App\Core\Router;

return static function (Router $router, AyudasController $controller): void {
    $router->get('/api/backoffice/v1/ayudas', [$controller, 'index'], ['permission' => AyudasPolicy::VIEW]);
};
