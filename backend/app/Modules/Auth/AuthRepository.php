<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Connection;
use PDO;

final class AuthRepository
{
    private function db(): PDO
    {
        return Connection::get();
    }

    public function findUser(string $username): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT u.id_usuario, u.usuario, u.nombre, u.hash_contrasena, u.activo,
                    r.id_rol, r.codigo AS rol_codigo, r.nombre AS rol_nombre
             FROM usuarios u
             INNER JOIN roles r ON r.id_rol = u.id_rol
             WHERE u.usuario = :usuario
             LIMIT 1'
        );
        $statement->execute(['usuario' => $username]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function permissionsForRole(int $roleId): array
    {
        // En la etapa inicial no existen las tablas permisos/roles_permisos.
        // Los permisos mínimos se resuelven por el código del rol para mantener
        // el login y la autorización del backend sin agregar estructura innecesaria.
        $statement = $this->db()->prepare(
            'SELECT codigo
             FROM roles
             WHERE id_rol = :rol AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['rol' => $roleId]);
        $roleCode = (string)($statement->fetchColumn() ?: '');

        return match ($roleCode) {
            'admin' => ['*'],
            'operador' => [
                'dashboard.view',
                'personas.view',
                'personas.manage',
            ],
            default => [],
        };
    }

    public function recentFailureCount(string $username, int $minutes): int
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*)
             FROM login_intentos intento
             WHERE intento.usuario = :usuario
               AND intento.exito = 0
               AND intento.creado_en >= :desde
               AND intento.id_intento > COALESCE((
                   SELECT MAX(exito.id_intento) FROM login_intentos exito
                   WHERE exito.usuario = :usuario_exito AND exito.exito = 1
               ), 0)'
        );
        $statement->bindValue(':usuario', $username);
        $statement->bindValue(':usuario_exito', $username);
        $statement->bindValue(':desde', date('Y-m-d H:i:s', time() - ($minutes * 60)));
        $statement->execute();
        return (int)$statement->fetchColumn();
    }

    public function recentIpFailureCount(string $ip, int $minutes): int
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(*) FROM login_intentos
             WHERE ip = :ip AND exito = 0 AND creado_en >= :desde'
        );
        $statement->bindValue(':ip', $ip);
        $statement->bindValue(':desde', date('Y-m-d H:i:s', time() - ($minutes * 60)));
        $statement->execute();
        return (int)$statement->fetchColumn();
    }

    public function recordAttempt(?int $userId, string $username, string $ip, string $userAgent, bool $success): void
    {
        $statement = $this->db()->prepare(
            'INSERT INTO login_intentos (id_usuario, usuario, ip, user_agent, exito, creado_en)
             VALUES (:id_usuario, :usuario, :ip, :user_agent, :exito, NOW())'
        );
        $statement->execute([
            'id_usuario' => $userId,
            'usuario' => substr($username, 0, 100),
            'ip' => $ip,
            'user_agent' => $userAgent,
            'exito' => $success ? 1 : 0,
        ]);
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $statement = $this->db()->prepare('UPDATE usuarios SET hash_contrasena = :hash, actualizado_en = NOW() WHERE id_usuario = :id');
        $statement->execute(['hash' => $hash, 'id' => $userId]);
    }

    public function updateLastLogin(int $userId): void
    {
        $this->db()->prepare('UPDATE usuarios SET ultimo_login_en = NOW() WHERE id_usuario = :id')->execute(['id' => $userId]);
    }

    public function createSession(int $userId, string $sessionHash, string $csrfToken, string $expiresAt, string $ip, string $userAgent): int
    {
        $statement = $this->db()->prepare(
            'INSERT INTO sesiones
             (id_usuario, session_hash, csrf_token, expira_en, ultimo_uso_en, ip, user_agent, activa, creado_en)
             VALUES (:usuario, :session_hash, :csrf_token, :expira_en, NOW(), :ip, :user_agent, 1, NOW())'
        );
        $statement->execute([
            'usuario' => $userId,
            'session_hash' => $sessionHash,
            'csrf_token' => $csrfToken,
            'expira_en' => $expiresAt,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    public function findActiveSession(string $sessionHash): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT s.id_sesion, s.id_usuario, s.csrf_token, s.expira_en, s.ultimo_uso_en, s.activa,
                    u.usuario, u.nombre, u.activo AS usuario_activo,
                    r.id_rol, r.codigo AS rol_codigo, r.nombre AS rol_nombre
             FROM sesiones s
             INNER JOIN usuarios u ON u.id_usuario = s.id_usuario
             INNER JOIN roles r ON r.id_rol = u.id_rol
             WHERE s.session_hash = :hash AND s.activa = 1
             LIMIT 1'
        );
        $statement->execute(['hash' => $sessionHash]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function touchSession(int $sessionId): void
    {
        $this->db()->prepare('UPDATE sesiones SET ultimo_uso_en = NOW() WHERE id_sesion = :id')->execute(['id' => $sessionId]);
    }

    public function deactivateSession(int $sessionId): void
    {
        $this->db()->prepare('UPDATE sesiones SET activa = 0 WHERE id_sesion = :id')->execute(['id' => $sessionId]);
    }

    public function deactivateUserSessions(int $userId): void
    {
        $this->db()->prepare('UPDATE sesiones SET activa = 0 WHERE id_usuario = :usuario')->execute(['usuario' => $userId]);
    }
}
