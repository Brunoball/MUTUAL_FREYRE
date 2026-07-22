<?php
declare(strict_types=1);

use App\Modules\Auth\AuthController;
use App\Core\Router;

return static function (Router $router, AuthController $controller): void {
    $router->post('/api/backoffice/v1/auth/login', [$controller, 'login'], ['auth' => false]);
    $router->get('/api/backoffice/v1/auth/me', [$controller, 'current']);
    $router->post('/api/backoffice/v1/auth/logout', [$controller, 'logout']);
};
