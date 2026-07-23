<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Ayudas\AyudasController;
use App\Modules\Ayudas\AyudasPolicy;

return static function (Router $router, AyudasController $controller): void {
    $router->get('/api/backoffice/v1/ayudas', [$controller, 'index'], ['permission' => AyudasPolicy::VIEW]);
    $router->get('/api/backoffice/v1/ayudas/catalogos', [$controller, 'catalogs'], ['permission' => AyudasPolicy::VIEW]);
    $router->get('/api/backoffice/v1/ayudas/parametros', [$controller, 'parameters'], ['permission' => AyudasPolicy::VIEW]);
    $router->get('/api/backoffice/v1/ayudas/parametros/cotizacion-bna', [$controller, 'currentBnaQuote'], ['permission' => AyudasPolicy::VIEW]);
    $router->get('/api/backoffice/v1/ayudas/detalle', [$controller, 'detail'], ['permission' => AyudasPolicy::VIEW]);
    $router->post('/api/backoffice/v1/ayudas/simular', [$controller, 'simulate'], ['permission' => AyudasPolicy::VIEW]);
    $router->post('/api/backoffice/v1/ayudas', [$controller, 'create'], ['permission' => AyudasPolicy::MANAGE]);
    $router->post('/api/backoffice/v1/ayudas/renovar', [$controller, 'renew'], ['permission' => AyudasPolicy::MANAGE]);
    $router->patch('/api/backoffice/v1/ayudas/anular', [$controller, 'annul'], ['permission' => AyudasPolicy::MANAGE]);
    $router->post('/api/backoffice/v1/ayudas/parametros/tasas', [$controller, 'saveRate'], ['permission' => AyudasPolicy::MANAGE]);
    $router->post('/api/backoffice/v1/ayudas/parametros/cotizacion-dolar', [$controller, 'saveQuote'], ['permission' => AyudasPolicy::MANAGE]);
};
