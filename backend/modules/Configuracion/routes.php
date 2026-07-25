<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Configuracion\ConfiguracionController;
use App\Modules\Configuracion\ConfiguracionPolicy;

return static function (Router $router, ConfiguracionController $controller): void {
    $router->get(
        '/api/backoffice/v1/configuracion',
        [$controller, 'index'],
        ['permission' => ConfiguracionPolicy::VIEW]
    );
    $router->get(
        '/api/backoffice/v1/configuracion/usuarios',
        [$controller, 'users'],
        ['permission' => ConfiguracionPolicy::VIEW]
    );
    $router->post(
        '/api/backoffice/v1/configuracion/usuarios',
        [$controller, 'createUser'],
        ['permission' => ConfiguracionPolicy::MANAGE]
    );
    $router->put(
        '/api/backoffice/v1/configuracion/usuarios',
        [$controller, 'updateUser'],
        ['permission' => ConfiguracionPolicy::MANAGE]
    );
    $router->patch(
        '/api/backoffice/v1/configuracion/usuarios/estado',
        [$controller, 'changeStatus'],
        ['permission' => ConfiguracionPolicy::MANAGE]
    );
    $router->delete(
        '/api/backoffice/v1/configuracion/usuarios',
        [$controller, 'deleteUser'],
        ['permission' => ConfiguracionPolicy::MANAGE]
    );
};
