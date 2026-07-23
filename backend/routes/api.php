<?php
declare(strict_types=1);

use App\Modules\Auth\AuthService;
use App\Modules\Auth\AuthController;
use App\Modules\Auth\AuthRepository;
use App\Modules\Dashboard\DashboardService;
use App\Modules\Dashboard\DashboardController;
use App\Modules\Dashboard\DashboardRepository;
use App\Modules\Configuracion\ConfiguracionController;
use App\Modules\Configuracion\ConfiguracionRepository;
use App\Modules\Configuracion\ConfiguracionService;
use App\Modules\Ayudas\AyudasController;
use App\Modules\Ayudas\AyudasRepository;
use App\Modules\Ayudas\AyudasService;
use App\Modules\Personas\PersonasService;
use App\Modules\Personas\PersonasController;
use App\Modules\Personas\PersonasRepository;
use App\Core\AuditLogger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

$audit = new AuditLogger();
$authService = new AuthService(new AuthRepository(), $audit);
$authController = new AuthController($authService);
$router = new Router($authService);

$router->get('/api/public/v1/health', static function (
    Request $request,
    ?array $session,
    string $correlationId
): never {
    Response::success([
        'servicio' => 'mutual-freyre-api',
        'estado' => 'ok',
        'arquitectura' => 'monolito_modular',
        'cliente' => 'unico',
        'etapa' => 'login_y_socios',
        'fecha' => date(DATE_ATOM),
    ]);
}, ['auth' => false]);

(require __DIR__ . '/../app/Modules/Auth/routes.php')($router, $authController);

$dashboardController = new DashboardController(new DashboardService(new DashboardRepository()));
$personasController = new PersonasController(new PersonasService(new PersonasRepository(), $audit));
$ayudasController = new AyudasController(new AyudasService(new AyudasRepository(), $audit));
$configuracionController = new ConfiguracionController(
    new ConfiguracionService(new ConfiguracionRepository(), $audit)
);

(require __DIR__ . '/../app/Modules/Dashboard/routes.php')($router, $dashboardController);
(require __DIR__ . '/../app/Modules/Personas/routes.php')($router, $personasController);
(require __DIR__ . '/../app/Modules/Ayudas/routes.php')($router, $ayudasController);
(require __DIR__ . '/../app/Modules/Configuracion/routes.php')($router, $configuracionController);

return $router;
