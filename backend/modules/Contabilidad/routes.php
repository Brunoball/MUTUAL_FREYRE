<?php
declare(strict_types=1);

use App\Modules\Contabilidad\ContabilidadController;
use App\Modules\Contabilidad\ContabilidadPolicy;
use App\Core\Router;

return static function (Router $router, ContabilidadController $controller): void {
    $router->get('/api/backoffice/v1/contabilidad', [$controller, 'index'], ['permission' => ContabilidadPolicy::VIEW]);
};
