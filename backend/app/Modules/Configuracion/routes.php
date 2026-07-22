<?php
declare(strict_types=1);

use App\Modules\Configuracion\ConfiguracionController;
use App\Modules\Configuracion\ConfiguracionPolicy;
use App\Core\Router;

return static function (Router $router, ConfiguracionController $controller): void {
    $router->get('/api/backoffice/v1/configuracion', [$controller, 'index'], ['permission' => ConfiguracionPolicy::VIEW]);
};
