<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Personas\PersonasController;
use App\Modules\Personas\PersonasPolicy;

return static function (Router $router, PersonasController $controller): void {
    $router->get('/api/backoffice/v1/personas', [$controller, 'index'], ['permission' => PersonasPolicy::VIEW]);
    $router->get('/api/backoffice/v1/personas/catalogos', [$controller, 'catalogs'], ['permission' => PersonasPolicy::VIEW]);
    $router->get('/api/backoffice/v1/personas/detalle', [$controller, 'detail'], ['permission' => PersonasPolicy::VIEW]);
    $router->post('/api/backoffice/v1/personas', [$controller, 'create'], ['permission' => PersonasPolicy::MANAGE]);
    $router->put('/api/backoffice/v1/personas', [$controller, 'update'], ['permission' => PersonasPolicy::MANAGE]);
    $router->patch('/api/backoffice/v1/personas/estado', [$controller, 'changeStatus'], ['permission' => PersonasPolicy::MANAGE]);
};
