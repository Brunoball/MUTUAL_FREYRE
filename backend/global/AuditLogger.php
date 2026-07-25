<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Auditoría append-only con snapshot del actor, cambios antes/después y cadena SHA-256.
 * Cuando recibe una conexión que ya está en transacción, el evento forma parte de la
 * misma unidad atómica que la operación de negocio.
 */
final class AuditLogger
{
    private const GENESIS_HASH = 'GENESIS';

    public function record(
        ?int $userId,
        string $module,
        string $action,
        ?string $entity = null,
        int|string|null $entityId = null,
        array $metadata = [],
        string $result = 'success',
        ?string $correlationId = null,
        ?array $actor = null,
        ?string $description = null,
        array $changes = [],
        ?PDO $connection = null
    ): int {
        $writer = function (PDO $db) use (
            $userId,
            $module,
            $action,
            $entity,
            $entityId,
            $metadata,
            $result,
            $correlationId,
            $actor,
            $description,
            $changes
        ): int {
            return $this->insert(
                $db,
                $userId,
                $module,
                $action,
                $entity,
                $entityId,
                $metadata,
                $result,
                $correlationId,
                $actor,
                $description,
                $changes
            );
        };

        if ($connection instanceof PDO) {
            if ($connection->inTransaction()) {
                return $writer($connection);
            }
            $connection->beginTransaction();
            try {
                $id = $writer($connection);
                $connection->commit();
                return $id;
            } catch (\Throwable $error) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $error;
            }
        }

        return Connection::transaction($writer);
    }

    public function recordForSession(
        array $session,
        string $module,
        string $action,
        ?string $entity,
        int|string|null $entityId,
        string $description,
        array $changes = [],
        array $metadata = [],
        string $result = 'success',
        ?string $correlationId = null,
        ?PDO $connection = null
    ): int {
        return $this->record(
            isset($session['id_usuario']) ? (int)$session['id_usuario'] : null,
            $module,
            $action,
            $entity,
            $entityId,
            $metadata,
            $result,
            $correlationId,
            $session,
            $description,
            $changes,
            $connection
        );
    }

    /**
     * Registra un evento sin permitir que una falla secundaria de auditoría
     * interrumpa un flujo de seguridad como login/logout. Las operaciones de
     * negocio deben continuar usando record()/recordForSession() para mantener
     * atomicidad estricta.
     */
    public function recordSafely(
        ?int $userId,
        string $module,
        string $action,
        ?string $entity = null,
        int|string|null $entityId = null,
        array $metadata = [],
        string $result = 'success',
        ?string $correlationId = null,
        ?array $actor = null,
        ?string $description = null,
        array $changes = [],
        ?PDO $connection = null
    ): ?int {
        try {
            return $this->record(
                $userId,
                $module,
                $action,
                $entity,
                $entityId,
                $metadata,
                $result,
                $correlationId,
                $actor,
                $description,
                $changes,
                $connection
            );
        } catch (\Throwable $error) {
            $safeCorrelationId = $correlationId !== null && $correlationId !== ''
                ? $correlationId
                : ClientContext::correlationId();
            Logger::error($error, $safeCorrelationId);
            $this->queuePendingEvent(
                $userId,
                $module,
                $action,
                $entity,
                $entityId,
                $metadata,
                $result,
                $safeCorrelationId,
                $actor,
                $description,
                $changes,
                $error
            );
            return null;
        }
    }

    public function recordForSessionSafely(
        array $session,
        string $module,
        string $action,
        ?string $entity,
        int|string|null $entityId,
        string $description,
        array $changes = [],
        array $metadata = [],
        string $result = 'success',
        ?string $correlationId = null,
        ?PDO $connection = null
    ): ?int {
        return $this->recordSafely(
            isset($session['id_usuario']) ? (int)$session['id_usuario'] : null,
            $module,
            $action,
            $entity,
            $entityId,
            $metadata,
            $result,
            $correlationId,
            $session,
            $description,
            $changes,
            $connection
        );
    }

    private function queuePendingEvent(
        ?int $userId,
        string $module,
        string $action,
        ?string $entity,
        int|string|null $entityId,
        array $metadata,
        string $result,
        string $correlationId,
        ?array $actor,
        ?string $description,
        array $changes,
        \Throwable $error
    ): void {
        try {
            $directory = dirname(__DIR__, 3) . '/storage/audit-pending';
            if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                return;
            }

            $payload = [
                'queued_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'user_id' => $userId,
                'module' => mb_substr($module, 0, 80),
                'action' => mb_substr($action, 0, 80),
                'entity' => $entity === null ? null : mb_substr($entity, 0, 100),
                'entity_id' => $entityId === null ? null : mb_substr((string)$entityId, 0, 80),
                'metadata' => AuditDiff::snapshot($metadata),
                'result' => mb_substr($result, 0, 30),
                'correlation_id' => mb_substr($correlationId, 0, 80),
                'actor' => AuditDiff::snapshot($actor ?? []),
                'description' => $description === null ? null : mb_substr($description, 0, 500),
                'changes' => AuditDiff::snapshot($changes),
                'original_error' => mb_substr($error->getMessage(), 0, 500),
            ];
            $name = sprintf(
                '%s_%s_%s.json',
                (new DateTimeImmutable())->format('Ymd_His_u'),
                preg_replace('/[^A-Za-z0-9_-]+/', '_', $module . '_' . $action),
                bin2hex(random_bytes(6))
            );
            @file_put_contents(
                $directory . '/' . $name,
                self::encode($payload),
                LOCK_EX
            );
        } catch (\Throwable) {
            // Nunca interrumpir autenticación por una falla de la cola secundaria.
        }
    }

    private function insert(
        PDO $db,
        ?int $userId,
        string $module,
        string $action,
        ?string $entity,
        int|string|null $entityId,
        array $metadata,
        string $result,
        ?string $correlationId,
        ?array $actor,
        ?string $description,
        array $changes
    ): int {
        $actorSnapshot = $this->actorSnapshot($actor, $userId);
        $metadata = AuditDiff::snapshot($metadata);
        $changes = AuditDiff::snapshot($changes);
        $createdAt = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $ip = ClientContext::ip();
        $userAgent = ClientContext::userAgent();

        $state = $db->query(
            'SELECT ultimo_hash FROM sis_auditoria_estado WHERE id_estado = 1 FOR UPDATE'
        )->fetch();
        if (!$state) {
            throw new RuntimeException('La cadena de auditoría no está inicializada. Ejecutá la migración de auditoría.');
        }
        $previousHash = trim((string)($state['ultimo_hash'] ?? '')) ?: self::GENESIS_HASH;

        $payload = [
            'version' => 1,
            'hash_anterior' => $previousHash,
            'id_usuario' => $userId,
            'actor_usuario' => $actorSnapshot['usuario'],
            'actor_nombre' => $actorSnapshot['nombre'],
            'actor_rol' => $actorSnapshot['rol'],
            'aplicacion' => $actorSnapshot['aplicacion'],
            'modulo' => mb_substr($module, 0, 80),
            'accion' => mb_substr($action, 0, 80),
            'entidad' => $entity === null ? null : mb_substr($entity, 0, 100),
            'id_entidad' => $entityId === null ? null : mb_substr((string)$entityId, 0, 80),
            'resultado' => mb_substr($result, 0, 30),
            'descripcion' => $description === null ? null : mb_substr($description, 0, 500),
            'metadata' => $metadata,
            'cambios' => $changes,
            'ip' => mb_substr($ip, 0, 45),
            'user_agent' => mb_substr($userAgent, 0, 255),
            'correlation_id' => $correlationId === null ? null : mb_substr($correlationId, 0, 80),
            'creado_en' => $createdAt,
        ];
        $eventHash = hash('sha256', self::canonicalJson($payload));

        $statement = $db->prepare(
            'INSERT INTO sis_auditoria_eventos
            (id_usuario, actor_usuario, actor_nombre, actor_rol, aplicacion,
             modulo, accion, entidad, id_entidad, resultado, descripcion,
             metadata, cambios, ip, user_agent, correlation_id,
             hash_anterior, hash_evento, creado_en)
            VALUES
            (:usuario, :actor_usuario, :actor_nombre, :actor_rol, :aplicacion,
             :modulo, :accion, :entidad, :id_entidad, :resultado, :descripcion,
             :metadata, :cambios, :ip, :user_agent, :correlation_id,
             :hash_anterior, :hash_evento, :creado_en)'
        );
        $statement->execute([
            'usuario' => $userId,
            'actor_usuario' => $actorSnapshot['usuario'],
            'actor_nombre' => $actorSnapshot['nombre'],
            'actor_rol' => $actorSnapshot['rol'],
            'aplicacion' => $actorSnapshot['aplicacion'],
            'modulo' => $payload['modulo'],
            'accion' => $payload['accion'],
            'entidad' => $payload['entidad'],
            'id_entidad' => $payload['id_entidad'],
            'resultado' => $payload['resultado'],
            'descripcion' => $payload['descripcion'],
            'metadata' => $metadata === [] ? null : self::encode($metadata),
            'cambios' => $changes === [] ? null : self::encode($changes),
            'ip' => $payload['ip'],
            'user_agent' => $payload['user_agent'],
            'correlation_id' => $payload['correlation_id'],
            'hash_anterior' => $previousHash,
            'hash_evento' => $eventHash,
            'creado_en' => $createdAt,
        ]);
        $eventId = (int)$db->lastInsertId();

        $updated = $db->prepare(
            'UPDATE sis_auditoria_estado
             SET ultimo_id_evento = :evento, ultimo_hash = :hash, actualizado_en = :fecha
             WHERE id_estado = 1'
        );
        $updated->execute([
            'evento' => $eventId,
            'hash' => $eventHash,
            'fecha' => $createdAt,
        ]);
        if ($updated->rowCount() !== 1) {
            throw new RuntimeException('No se pudo actualizar el estado de integridad de auditoría.');
        }

        return $eventId;
    }

    public static function canonicalJson(array $payload): string
    {
        return self::encode(self::sortRecursively($payload));
    }

    private static function encode(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR
        );
        return $json;
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::sortRecursively($child);
        }
        return $value;
    }

    private function actorSnapshot(?array $actor, ?int $userId): array
    {
        $actor ??= [];
        $roles = $actor['roles'] ?? [];
        $role = (string)($actor['rol'] ?? $actor['rol_codigo'] ?? '');
        if ($role === '' && is_array($roles) && isset($roles[0])) {
            $first = $roles[0];
            $role = is_array($first)
                ? (string)($first['nombre'] ?? $first['codigo'] ?? '')
                : (string)$first;
        }

        $application = $actor['aplicacion_codigo'] ?? null;
        if (($application === null || $application === '') && is_array($actor['aplicacion'] ?? null)) {
            $application = $actor['aplicacion']['codigo'] ?? $actor['aplicacion']['nombre'] ?? null;
        } elseif ($application === null || $application === '') {
            $application = $actor['aplicacion'] ?? null;
        }
        if (!is_scalar($application) || trim((string)$application) === '') {
            $application = 'backoffice';
        }

        return [
            'usuario' => mb_substr((string)($actor['usuario'] ?? $actor['username'] ?? ($userId === null ? 'sistema' : 'usuario-' . $userId)), 0, 120),
            'nombre' => mb_substr((string)($actor['nombre'] ?? $actor['actor_nombre'] ?? ($userId === null ? 'Proceso del sistema' : 'Usuario ' . $userId)), 0, 180),
            'rol' => mb_substr($role !== '' ? $role : ($userId === null ? 'SISTEMA' : 'SIN_SNAPSHOT'), 0, 120),
            'aplicacion' => mb_substr((string)$application, 0, 80),
        ];
    }
}
