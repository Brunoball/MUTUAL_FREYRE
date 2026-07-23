<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\IdentityConnection;
use PDO;

final class AuthRepository
{
    private function db(): PDO
    {
        return IdentityConnection::get();
    }

    public function findApplication(string $applicationCode): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT id_aplicacion, codigo, nombre, activo
             FROM idn_aplicaciones
             WHERE codigo = :codigo
             LIMIT 1'
        );
        $statement->execute(['codigo' => $applicationCode]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findUser(string $credential, string $applicationCode): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT u.id_usuario, u.usuario, u.nombre, u.email, u.hash_contrasena,
                    u.activo, u.bloqueado_hasta, u.version_seguridad,
                    ua.id_usuario_aplicacion, ua.activo AS acceso_activo,
                    ua.tipo_perfil, ua.id_referencia_externa,
                    a.id_aplicacion, a.codigo AS aplicacion_codigo,
                    a.nombre AS aplicacion_nombre, a.activo AS aplicacion_activa
             FROM idn_usuarios u
             INNER JOIN idn_usuarios_aplicaciones ua ON ua.id_usuario = u.id_usuario
             INNER JOIN idn_aplicaciones a ON a.id_aplicacion = ua.id_aplicacion
             WHERE (u.usuario = :usuario OR u.email = :email)
               AND a.codigo = :aplicacion
             LIMIT 1'
        );
        $statement->execute([
            'usuario' => $credential,
            'email' => $credential,
            'aplicacion' => $applicationCode,
        ]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function rolesForAccess(int $userApplicationId): array
    {
        $statement = $this->db()->prepare(
            'SELECT r.id_rol, r.codigo, r.nombre, r.es_super_admin
             FROM idn_usuarios_roles ur
             INNER JOIN idn_usuarios_aplicaciones ua
                     ON ua.id_usuario_aplicacion = ur.id_usuario_aplicacion
             INNER JOIN idn_roles r
                     ON r.id_rol = ur.id_rol
                    AND r.id_aplicacion = ua.id_aplicacion
             WHERE ur.id_usuario_aplicacion = :acceso
               AND r.activo = 1
             ORDER BY r.es_super_admin DESC, r.nombre ASC'
        );
        $statement->execute(['acceso' => $userApplicationId]);
        return $statement->fetchAll() ?: [];
    }

    public function permissionsForAccess(int $userApplicationId): array
    {
        $superAdmin = $this->db()->prepare(
            'SELECT COUNT(*)
             FROM idn_usuarios_roles ur
             INNER JOIN idn_usuarios_aplicaciones ua
                     ON ua.id_usuario_aplicacion = ur.id_usuario_aplicacion
             INNER JOIN idn_roles r
                     ON r.id_rol = ur.id_rol
                    AND r.id_aplicacion = ua.id_aplicacion
             WHERE ur.id_usuario_aplicacion = :acceso
               AND r.activo = 1
               AND r.es_super_admin = 1'
        );
        $superAdmin->execute(['acceso' => $userApplicationId]);
        if ((int)$superAdmin->fetchColumn() > 0) {
            return ['*'];
        }

        $statement = $this->db()->prepare(
            'SELECT DISTINCT p.codigo
             FROM idn_usuarios_roles ur
             INNER JOIN idn_usuarios_aplicaciones ua
                     ON ua.id_usuario_aplicacion = ur.id_usuario_aplicacion
             INNER JOIN idn_roles r
                     ON r.id_rol = ur.id_rol
                    AND r.id_aplicacion = ua.id_aplicacion
                    AND r.activo = 1
             INNER JOIN idn_roles_permisos rp ON rp.id_rol = r.id_rol
             INNER JOIN idn_permisos p
                     ON p.id_permiso = rp.id_permiso
                    AND p.id_aplicacion = r.id_aplicacion
                    AND p.activo = 1
             WHERE ur.id_usuario_aplicacion = :acceso
             ORDER BY p.codigo ASC'
        );
        $statement->execute(['acceso' => $userApplicationId]);
        return array_values(array_map(
            static fn (array $row): string => (string)$row['codigo'],
            $statement->fetchAll() ?: []
        ));
    }

    public function recentFailureCount(int $applicationId, string $credential, int $minutes): int
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*)
             FROM idn_login_intentos intento
             WHERE intento.id_aplicacion = :aplicacion
               AND intento.credencial = :credencial
               AND intento.exito = 0
               AND intento.creado_en >= :desde
               AND intento.id_intento > COALESCE((
                   SELECT MAX(exito.id_intento)
                   FROM idn_login_intentos exito
                   WHERE exito.id_aplicacion = :aplicacion_exito
                     AND exito.credencial = :credencial_exito
                     AND exito.exito = 1
               ), 0)'
        );
        $statement->execute([
            'aplicacion' => $applicationId,
            'credencial' => $credential,
            'desde' => date('Y-m-d H:i:s', time() - ($minutes * 60)),
            'aplicacion_exito' => $applicationId,
            'credencial_exito' => $credential,
        ]);
        return (int)$statement->fetchColumn();
    }

    public function recentIpFailureCount(int $applicationId, string $ip, int $minutes): int
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*)
             FROM idn_login_intentos
             WHERE id_aplicacion = :aplicacion
               AND ip = :ip
               AND exito = 0
               AND creado_en >= :desde'
        );
        $statement->execute([
            'aplicacion' => $applicationId,
            'ip' => $ip,
            'desde' => date('Y-m-d H:i:s', time() - ($minutes * 60)),
        ]);
        return (int)$statement->fetchColumn();
    }

    public function recordAttempt(
        int $applicationId,
        ?int $userId,
        string $credential,
        string $ip,
        string $userAgent,
        bool $success,
        ?string $reason = null
    ): void {
        $statement = $this->db()->prepare(
            'INSERT INTO idn_login_intentos
             (id_aplicacion, id_usuario, credencial, ip, user_agent, exito, motivo, creado_en)
             VALUES (:aplicacion, :usuario, :credencial, :ip, :user_agent, :exito, :motivo, NOW())'
        );
        $statement->execute([
            'aplicacion' => $applicationId,
            'usuario' => $userId,
            'credencial' => mb_substr($credential, 0, 180),
            'ip' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 255),
            'exito' => $success ? 1 : 0,
            'motivo' => $reason === null ? null : mb_substr($reason, 0, 80),
        ]);
    }

    public function updatePasswordHash(int $userId, string $hash): int
    {
        $statement = $this->db()->prepare(
            'UPDATE idn_usuarios
             SET hash_contrasena = :hash,
                 version_seguridad = version_seguridad + 1,
                 actualizado_en = NOW()
             WHERE id_usuario = :id'
        );
        $statement->execute(['hash' => $hash, 'id' => $userId]);

        $version = $this->db()->prepare(
            'SELECT version_seguridad FROM idn_usuarios WHERE id_usuario = :id LIMIT 1'
        );
        $version->execute(['id' => $userId]);
        return max(1, (int)$version->fetchColumn());
    }

    public function updateLastLogin(int $userId, int $userApplicationId): void
    {
        $this->db()->prepare(
            'UPDATE idn_usuarios SET ultimo_login_en = NOW() WHERE id_usuario = :id'
        )->execute(['id' => $userId]);

        $this->db()->prepare(
            'UPDATE idn_usuarios_aplicaciones
             SET ultimo_acceso_en = NOW(), actualizado_en = NOW()
             WHERE id_usuario_aplicacion = :id'
        )->execute(['id' => $userApplicationId]);
    }

    public function createSession(
        int $userApplicationId,
        int $securityVersion,
        string $sessionHash,
        string $csrfToken,
        string $expiresAt,
        string $ip,
        string $userAgent
    ): int {
        $statement = $this->db()->prepare(
            'INSERT INTO idn_sesiones
             (id_usuario_aplicacion, tipo, session_hash, csrf_token, version_seguridad,
              expira_en, ultimo_uso_en, ip, user_agent, activa, creado_en)
             VALUES (:acceso, \'WEB\', :session_hash, :csrf_token, :version_seguridad,
                     :expira_en, NOW(), :ip, :user_agent, 1, NOW())'
        );
        $statement->execute([
            'acceso' => $userApplicationId,
            'session_hash' => $sessionHash,
            'csrf_token' => $csrfToken,
            'version_seguridad' => $securityVersion,
            'expira_en' => $expiresAt,
            'ip' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 255),
        ]);
        return (int)$this->db()->lastInsertId();
    }

    public function findActiveSession(string $sessionHash, string $applicationCode): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT s.id_sesion, s.csrf_token, s.expira_en, s.ultimo_uso_en, s.activa,
                    ua.id_usuario_aplicacion, ua.activo AS acceso_activo,
                    ua.tipo_perfil, ua.id_referencia_externa,
                    u.id_usuario, u.usuario, u.nombre, u.email,
                    u.activo AS usuario_activo, u.bloqueado_hasta, u.version_seguridad,
                    a.id_aplicacion, a.codigo AS aplicacion_codigo,
                    a.nombre AS aplicacion_nombre, a.activo AS aplicacion_activa
             FROM idn_sesiones s
             INNER JOIN idn_usuarios_aplicaciones ua
                     ON ua.id_usuario_aplicacion = s.id_usuario_aplicacion
             INNER JOIN idn_usuarios u ON u.id_usuario = ua.id_usuario
             INNER JOIN idn_aplicaciones a ON a.id_aplicacion = ua.id_aplicacion
             WHERE s.session_hash = :hash
               AND s.activa = 1
               AND s.version_seguridad = u.version_seguridad
               AND a.codigo = :aplicacion
             LIMIT 1'
        );
        $statement->execute([
            'hash' => $sessionHash,
            'aplicacion' => $applicationCode,
        ]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function touchSession(int $sessionId): void
    {
        $this->db()->prepare(
            'UPDATE idn_sesiones SET ultimo_uso_en = NOW() WHERE id_sesion = :id'
        )->execute(['id' => $sessionId]);
    }

    public function deactivateSession(int $sessionId, string $reason = 'LOGOUT'): void
    {
        $this->db()->prepare(
            'UPDATE idn_sesiones
             SET activa = 0, revocada_en = COALESCE(revocada_en, NOW()),
                 motivo_revocacion = COALESCE(motivo_revocacion, :motivo)
             WHERE id_sesion = :id'
        )->execute([
            'id' => $sessionId,
            'motivo' => mb_substr($reason, 0, 80),
        ]);
    }

    public function deactivateUserSessions(int $userId, string $reason = 'USER_DISABLED'): void
    {
        $this->db()->prepare(
            'UPDATE idn_sesiones s
             INNER JOIN idn_usuarios_aplicaciones ua
                     ON ua.id_usuario_aplicacion = s.id_usuario_aplicacion
             SET s.activa = 0,
                 s.revocada_en = COALESCE(s.revocada_en, NOW()),
                 s.motivo_revocacion = COALESCE(s.motivo_revocacion, :motivo)
             WHERE ua.id_usuario = :usuario AND s.activa = 1'
        )->execute([
            'usuario' => $userId,
            'motivo' => mb_substr($reason, 0, 80),
        ]);
    }

    public function recordSecurityEvent(
        int $applicationId,
        ?int $userId,
        string $event,
        string $result,
        string $ip,
        string $userAgent,
        string $correlationId,
        array $metadata = []
    ): void {
        $statement = $this->db()->prepare(
            'INSERT INTO idn_eventos_seguridad
             (id_aplicacion, id_usuario, evento, resultado, metadata, ip, user_agent,
              correlation_id, creado_en)
             VALUES (:aplicacion, :usuario, :evento, :resultado, :metadata, :ip,
                     :user_agent, :correlation_id, NOW())'
        );
        $statement->execute([
            'aplicacion' => $applicationId,
            'usuario' => $userId,
            'evento' => mb_substr($event, 0, 80),
            'resultado' => mb_substr($result, 0, 30),
            'metadata' => $metadata === [] ? null : json_encode(
                $metadata,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
            ),
            'ip' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 255),
            'correlation_id' => mb_substr($correlationId, 0, 80),
        ]);
    }
}
