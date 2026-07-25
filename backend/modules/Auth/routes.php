<?php
declare(strict_types=1);

use App\Modules\Auth\AuthController;
use App\Core\Router;

return static function (Router $router, AuthController $controller): void {
    $router->post('/api/backoffice/v1/auth/login', [$controller, 'login'], ['auth' => false]);
    // Endpoint de estado: valida la cookie internamente y responde 200 también
    // cuando el visitante todavía no inició sesión. Las rutas de negocio siguen
    // protegidas por Router::authenticate().
    $router->get('/api/backoffice/v1/auth/me', [$controller, 'current'], ['auth' => false]);
    $router->post('/api/backoffice/v1/auth/logout', [$controller, 'logout']);
};
