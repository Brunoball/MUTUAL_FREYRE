<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;

final class InformeRiesgoController
{
    public function __construct(private readonly InformeRiesgoService $service) {}

    public function index(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->list([
            'cuit' => $request->query('cuit', ''),
            'id_persona' => $request->query('id_persona'),
            'limite' => $request->query('limite', 30),
        ]));
    }

    public function detail(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->detail($this->reportId($request)));
    }

    public function generate(Request $request, array $session, string $correlationId): never
    {
        Response::success(
            $this->service->generate($request->json(), $session, $correlationId),
            201
        );
    }

    public function evaluate(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->saveEvaluation(
            $this->reportId($request),
            $request->json(),
            $session,
            $correlationId
        ));
    }

    public function refreshRepet(
        Request $request,
        array $session,
        string $correlationId
    ): never {
        Response::success($this->service->refreshRepet(
            $this->reportId($request),
            $session,
            $correlationId
        ));
    }

    public function dictate(Request $request, array $session, string $correlationId): never
    {
        Response::success($this->service->dictate(
            $this->reportId($request),
            $request->json(),
            $session,
            $correlationId
        ));
    }

    public function refreshBcra(
        Request $request,
        array $session,
        string $correlationId
    ): never {
        Response::success($this->service->refreshBcra(
            $this->reportId($request),
            $session,
            $correlationId
        ));
    }

    public function evidence(Request $request, array $session, string $correlationId): never
    {
        $documentId = filter_var($request->query('id_documento'), FILTER_VALIDATE_INT);
        if (!$documentId || $documentId < 1) {
            throw new ApiException(
                'Indicá una evidencia válida.',
                'INVALID_EVIDENCE_ID',
                422
            );
        }
        $file = $this->service->evidence((int)$documentId);
        $downloadName = preg_replace(
            '/[^A-Za-z0-9._ -]+/u',
            '_',
            basename((string)$file['name'])
        ) ?: 'evidencia';

        header('Content-Type: ' . (string)$file['mime']);
        header('Content-Length: ' . (int)$file['size']);
        header('Content-Disposition: attachment; filename="' . addcslashes(
            $downloadName,
            "\\\""
        ) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Evidence-SHA256: ' . (string)$file['hash']);
        readfile((string)$file['path']);
        exit;
    }

    private function reportId(Request $request): int
    {
        $id = filter_var($request->query('id'), FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new ApiException(
                'Indicá un informe válido.',
                'INVALID_RISK_REPORT_ID',
                422
            );
        }
        return (int)$id;
    }
}
