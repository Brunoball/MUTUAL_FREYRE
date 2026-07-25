<?php
declare(strict_types=1);

use App\Modules\Documentos\DocumentosController;
use App\Modules\Documentos\DocumentosPolicy;
use App\Core\Router;

return static function (Router $router, DocumentosController $controller): void {
    $router->get('/api/backoffice/v1/documentos', [$controller, 'index'], ['permission' => DocumentosPolicy::VIEW]);
};
