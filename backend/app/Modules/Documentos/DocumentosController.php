<?php
declare(strict_types=1);

namespace App\Modules\Documentos;

use App\Modules\Documentos\DocumentosService;
use App\Core\Request;
use App\Core\Response;

final class DocumentosController
{
    public function __construct(private readonly DocumentosService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->structure());
    }
}
