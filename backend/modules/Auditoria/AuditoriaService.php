<?php
declare(strict_types=1);

namespace App\Modules\Auditoria;

use App\Core\ApiException;
use DateTimeImmutable;

final class AuditoriaService
{
    public function __construct(private readonly AuditoriaRepository $repository) {}

    public function index(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        return $this->repository->list($filters) + [
            'resumen' => $this->repository->summary(),
            'catalogos' => $this->repository->catalogs(),
        ];
    }

    public function detail(int $eventId): array
    {
        if ($eventId < 1) {
            throw new ApiException('Indicá un evento de auditoría válido.', 'INVALID_AUDIT_EVENT_ID', 422);
        }
        $event = $this->repository->find($eventId);
        if (!$event) {
            throw new ApiException('El evento de auditoría no existe.', 'AUDIT_EVENT_NOT_FOUND', 404);
        }
        return $event;
    }

    public function integrity(): array
    {
        return $this->repository->verifyIntegrity();
    }

    private function normalizeFilters(array $filters): array
    {
        foreach (['desde', 'hasta'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value === '') continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new ApiException('La fecha indicada no es válida.', 'INVALID_AUDIT_DATE', 422, [
                    $field => 'Usá el formato AAAA-MM-DD.',
                ]);
            }
        }
        if (
            !empty($filters['desde'])
            && !empty($filters['hasta'])
            && (string)$filters['desde'] > (string)$filters['hasta']
        ) {
            throw new ApiException('La fecha desde no puede ser posterior a la fecha hasta.', 'INVALID_AUDIT_RANGE', 422);
        }
        return $filters;
    }
}
