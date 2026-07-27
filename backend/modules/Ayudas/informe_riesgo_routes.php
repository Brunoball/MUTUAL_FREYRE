<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Ayudas\InformeRiesgoController;
use App\Modules\Ayudas\InformeRiesgoPolicy;

return static function (Router $router, InformeRiesgoController $controller): void {
    $base = '/api/backoffice/v1/ayudas/informes-riesgo';

    $router->get($base, [$controller, 'index'], [
        'permission' => InformeRiesgoPolicy::VIEW,
    ]);
    $router->get($base . '/detalle', [$controller, 'detail'], [
        'permission' => InformeRiesgoPolicy::VIEW,
    ]);
    $router->post($base . '/generar', [$controller, 'generate'], [
        'permission' => InformeRiesgoPolicy::GENERATE,
    ]);
    $router->post($base . '/evaluacion-uif', [$controller, 'evaluate'], [
        'permission' => InformeRiesgoPolicy::EVALUATE,
    ]);
    $router->post($base . '/dictamen', [$controller, 'dictate'], [
        'permission' => InformeRiesgoPolicy::DECIDE,
    ]);
    $router->post($base . '/refrescar-bcra', [$controller, 'refreshBcra'], [
        'permission' => InformeRiesgoPolicy::REFRESH,
    ]);
    $router->post($base . '/refrescar-repet', [$controller, 'refreshRepet'], [
        'permission' => InformeRiesgoPolicy::REFRESH,
    ]);
    $router->get($base . '/evidencia', [$controller, 'evidence'], [
        'permission' => InformeRiesgoPolicy::VIEW,
    ]);
};
