<?php
declare(strict_types=1);

use App\Modules\Personas\PersonasController;
use App\Modules\Personas\PersonasPolicy;
use App\Core\Router;

return static function (Router $router, PersonasController $controller): void {
    $router->get('/api/backoffice/v1/personas', [$controller, 'index'], ['permission' => PersonasPolicy::VIEW]);
};
