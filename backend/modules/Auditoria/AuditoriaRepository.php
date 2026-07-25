<?php
declare(strict_types=1);

namespace App\Modules\Auditoria;

use App\Core\AuditLogger;
use App\Core\Connection;
use PDO;

final class AuditoriaRepository
{
    private function db(): PDO
    {
        return Connection::get();
    }

    public function list(array $filters): array
    {
        [$where, $params] = $this->where($filters);
        $page = max(1, (int)($filters['pagina'] ?? 1));
        $limit = max(10, min(200, (int)($filters['limite'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $count = $this->db()->prepare(
            'SELECT COUNT(*) FROM sis_auditoria_eventos e ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '')
        );
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $sql = 'SELECT e.id_evento, e.id_usuario, e.actor_usuario, e.actor_nombre,
                       e.actor_rol, e.aplicacion, e.modulo, e.accion, e.entidad,
                       e.id_entidad, e.resultado, e.descripcion, e.metadata,
                       e.cambios, e.ip, e.user_agent, e.correlation_id,
                       e.hash_version, e.hash_anterior, e.hash_evento, e.creado_en
                FROM sis_auditoria_eventos e '
                . ($where ? 'WHERE ' . implode(' AND ', $where) : '')
                . ' ORDER BY e.id_evento DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
        $items = array_map([$this, 'hydrate'], $statement->fetchAll() ?: []);

        return [
            'items' => $items,
            'paginacion' => [
                'pagina' => $page,
                'limite' => $limit,
                'total' => $total,
                'paginas' => max(1, (int)ceil($total / $limit)),
            ],
        ];
    }

    public function find(int $eventId): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT e.* FROM sis_auditoria_eventos e WHERE e.id_evento = :id LIMIT 1'
        );
        $statement->execute(['id' => $eventId]);
        $row = $statement->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function catalogs(): array
    {
        return [
            'modulos' => $this->column('SELECT DISTINCT modulo FROM sis_auditoria_eventos ORDER BY modulo'),
            'acciones' => $this->column('SELECT DISTINCT accion FROM sis_auditoria_eventos ORDER BY accion'),
            'resultados' => $this->column('SELECT DISTINCT resultado FROM sis_auditoria_eventos ORDER BY resultado'),
            'usuarios' => $this->rows(
                'SELECT actor_usuario AS usuario, MAX(actor_nombre) AS nombre,
                        MAX(actor_rol) AS rol, MAX(id_usuario) AS id_usuario
                 FROM sis_auditoria_eventos
                 WHERE actor_usuario IS NOT NULL AND actor_usuario <> \'\'
                 GROUP BY actor_usuario
                 ORDER BY nombre, usuario'
            ),
        ];
    }

    public function summary(): array
    {
        $row = $this->db()->query(
            'SELECT COUNT(*) AS total,
                    SUM(creado_en >= NOW() - INTERVAL 24 HOUR) AS ultimas_24h,
                    SUM(resultado <> \'success\') AS no_exitosos,
                    COUNT(DISTINCT actor_usuario) AS actores,
                    MAX(creado_en) AS ultimo_evento
             FROM sis_auditoria_eventos'
        )->fetch() ?: [];

        return [
            'total' => (int)($row['total'] ?? 0),
            'ultimas_24h' => (int)($row['ultimas_24h'] ?? 0),
            'no_exitosos' => (int)($row['no_exitosos'] ?? 0),
            'actores' => (int)($row['actores'] ?? 0),
            'ultimo_evento' => $row['ultimo_evento'] ?? null,
        ];
    }

    public function verifyIntegrity(): array
    {
        $legacyCount = (int)$this->db()->query(
            'SELECT COUNT(*) FROM sis_auditoria_eventos WHERE hash_version = 0 OR hash_evento IS NULL'
        )->fetchColumn();
        $statement = $this->db()->query(
            'SELECT id_evento, id_usuario, actor_usuario, actor_nombre, actor_rol,
                    aplicacion, modulo, accion, entidad, id_entidad, resultado,
                    descripcion, metadata, cambios, ip, user_agent, correlation_id,
                    hash_version, hash_anterior, hash_evento, creado_en
             FROM sis_auditoria_eventos
             WHERE hash_version = 1
             ORDER BY id_evento ASC'
        );

        $expectedPrevious = 'GENESIS';
        $verified = 0;
        $lastVerifiedId = null;
        $firstInvalid = null;
        while ($row = $statement->fetch()) {
            $metadata = $this->decode($row['metadata'] ?? null);
            $changes = $this->decode($row['cambios'] ?? null);
            $payload = [
                'version' => 1,
                'hash_anterior' => (string)($row['hash_anterior'] ?? ''),
                'id_usuario' => $row['id_usuario'] === null ? null : (int)$row['id_usuario'],
                'actor_usuario' => (string)($row['actor_usuario'] ?? ''),
                'actor_nombre' => (string)($row['actor_nombre'] ?? ''),
                'actor_rol' => (string)($row['actor_rol'] ?? ''),
                'aplicacion' => (string)($row['aplicacion'] ?? ''),
                'modulo' => (string)$row['modulo'],
                'accion' => (string)$row['accion'],
                'entidad' => $row['entidad'] === null ? null : (string)$row['entidad'],
                'id_entidad' => $row['id_entidad'] === null ? null : (string)$row['id_entidad'],
                'resultado' => (string)$row['resultado'],
                'descripcion' => $row['descripcion'] === null ? null : (string)$row['descripcion'],
                'metadata' => $metadata,
                'cambios' => $changes,
                'ip' => (string)($row['ip'] ?? ''),
                'user_agent' => (string)($row['user_agent'] ?? ''),
                'correlation_id' => $row['correlation_id'] === null ? null : (string)$row['correlation_id'],
                'creado_en' => (string)$row['creado_en'],
            ];
            $calculated = hash('sha256', AuditLogger::canonicalJson($payload));
            if (
                !hash_equals($expectedPrevious, (string)($row['hash_anterior'] ?? ''))
                || !hash_equals($calculated, (string)($row['hash_evento'] ?? ''))
            ) {
                $firstInvalid = [
                    'id_evento' => (int)$row['id_evento'],
                    'hash_anterior_esperado' => $expectedPrevious,
                    'hash_anterior_guardado' => (string)($row['hash_anterior'] ?? ''),
                    'hash_evento_esperado' => $calculated,
                    'hash_evento_guardado' => (string)($row['hash_evento'] ?? ''),
                ];
                break;
            }
            $expectedPrevious = (string)$row['hash_evento'];
            $lastVerifiedId = (int)$row['id_evento'];
            $verified++;
        }

        $state = $this->db()->query(
            'SELECT ultimo_id_evento, ultimo_hash, actualizado_en
             FROM sis_auditoria_estado WHERE id_estado = 1 LIMIT 1'
        )->fetch() ?: [];
        $stateId = isset($state['ultimo_id_evento']) && $state['ultimo_id_evento'] !== null
            ? (int)$state['ultimo_id_evento']
            : null;
        $stateMatches = $firstInvalid === null
            && hash_equals($expectedPrevious, (string)($state['ultimo_hash'] ?? 'GENESIS'))
            && $stateId === $lastVerifiedId;

        return [
            'integra' => $firstInvalid === null && $stateMatches,
            'eventos_verificados' => $verified,
            'eventos_legacy_sin_sello' => $legacyCount,
            'primer_evento_invalido' => $firstInvalid,
            'estado_cadena_coincide' => $stateMatches,
            'ultimo_id_evento' => $stateId,
            'ultimo_hash' => $state['ultimo_hash'] ?? 'GENESIS',
            'verificado_en' => date(DATE_ATOM),
        ];
    }

    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        $search = trim((string)($filters['buscar'] ?? ''));
        if ($search !== '') {
            $where[] = '(e.descripcion LIKE :buscar OR e.actor_usuario LIKE :buscar
                       OR e.actor_nombre LIKE :buscar OR e.entidad LIKE :buscar
                       OR e.id_entidad LIKE :buscar OR e.correlation_id LIKE :buscar)';
            $params['buscar'] = '%' . $search . '%';
        }
        foreach (['modulo', 'accion', 'resultado', 'entidad'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '') {
                $where[] = "e.{$field} = :{$field}";
                $params[$field] = $value;
            }
        }
        $user = trim((string)($filters['usuario'] ?? ''));
        if ($user !== '') {
            $where[] = 'e.actor_usuario = :usuario';
            $params['usuario'] = $user;
        }
        $from = trim((string)($filters['desde'] ?? ''));
        if ($from !== '') {
            $where[] = 'e.creado_en >= :desde';
            $params['desde'] = $from . ' 00:00:00';
        }
        $to = trim((string)($filters['hasta'] ?? ''));
        if ($to !== '') {
            $where[] = 'e.creado_en < DATE_ADD(:hasta, INTERVAL 1 DAY)';
            $params['hasta'] = $to . ' 00:00:00';
        }
        return [$where, $params];
    }

    private function hydrate(array $row): array
    {
        $row['id_evento'] = (int)$row['id_evento'];
        $row['id_usuario'] = $row['id_usuario'] === null ? null : (int)$row['id_usuario'];
        $row['hash_version'] = (int)($row['hash_version'] ?? 0);
        $row['metadata'] = $this->decode($row['metadata'] ?? null);
        $row['cambios'] = $this->decode($row['cambios'] ?? null);
        $row['sellado'] = $row['hash_version'] === 1 && !empty($row['hash_evento']);
        return $row;
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function column(string $sql): array
    {
        return array_values(array_map('strval', $this->db()->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    private function rows(string $sql): array
    {
        return $this->db()->query($sql)->fetchAll() ?: [];
    }
}
