<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Connection;
use App\Core\ClientContext;

final class AuditLogger
{
    public function record(
        ?int $userId,
        string $module,
        string $action,
        ?string $entity = null,
        int|string|null $entityId = null,
        array $metadata = [],
        string $result = 'success',
        ?string $correlationId = null
    ): void {
        $statement = Connection::get()->prepare(
            'INSERT INTO auditoria_eventos
            (id_usuario, modulo, accion, entidad, id_entidad, resultado, metadata, ip, user_agent, correlation_id, creado_en)
            VALUES (:usuario, :modulo, :accion, :entidad, :id_entidad, :resultado, :metadata, :ip, :user_agent, :correlation_id, NOW())'
        );
        $statement->execute([
            'usuario' => $userId,
            'modulo' => substr($module, 0, 80),
            'accion' => substr($action, 0, 80),
            'entidad' => $entity ? substr($entity, 0, 100) : null,
            'id_entidad' => $entityId === null ? null : (string)$entityId,
            'resultado' => substr($result, 0, 30),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'ip' => ClientContext::ip(),
            'user_agent' => ClientContext::userAgent(),
            'correlation_id' => $correlationId,
        ]);
    }
}
