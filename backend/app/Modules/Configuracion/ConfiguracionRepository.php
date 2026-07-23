<?php
declare(strict_types=1);

namespace App\Modules\Configuracion;

use App\Core\IdentityConnection;
use PDO;

final class ConfiguracionRepository
{
    private function db(): PDO
    {
        return IdentityConnection::get();
    }

    public function application(int $applicationId): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT id_aplicacion, codigo, nombre, descripcion, activo
             FROM idn_aplicaciones
             WHERE id_aplicacion = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $applicationId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function users(int $applicationId): array
    {
        $statement = $this->db()->prepare(
            'SELECT ua.id_usuario_aplicacion AS id_acceso,
                    ua.id_usuario,
                    ua.tipo_perfil,
                    ua.activo AS acceso_activo,
                    ua.ultimo_acceso_en,
                    ua.creado_en AS acceso_creado_en,
                    u.usuario,
                    u.nombre,
                    u.email,
                    u.activo AS usuario_activo,
                    u.bloqueado_hasta,
                    u.ultimo_login_en,
                    r.id_rol,
                    r.codigo AS rol_codigo,
                    r.nombre AS rol_nombre,
                    r.es_super_admin
             FROM idn_usuarios_aplicaciones ua
             INNER JOIN idn_usuarios u ON u.id_usuario = ua.id_usuario
             LEFT JOIN idn_usuarios_roles ur
                    ON ur.id_usuario_aplicacion = ua.id_usuario_aplicacion
             LEFT JOIN idn_roles r
                    ON r.id_rol = ur.id_rol
                   AND r.id_aplicacion = ua.id_aplicacion
                   AND r.activo = 1
             WHERE ua.id_aplicacion = :aplicacion
             ORDER BY u.nombre ASC, u.usuario ASC, r.es_super_admin DESC, r.nombre ASC'
        );
        $statement->execute(['aplicacion' => $applicationId]);

        $users = [];
        foreach ($statement->fetchAll() ?: [] as $row) {
            $accessId = (int)$row['id_acceso'];
            if (!isset($users[$accessId])) {
                $users[$accessId] = [
                    'id_acceso' => $accessId,
                    'id_usuario' => (int)$row['id_usuario'],
                    'usuario' => (string)$row['usuario'],
                    'nombre' => (string)$row['nombre'],
                    'email' => (string)($row['email'] ?? ''),
                    'tipo_perfil' => (string)$row['tipo_perfil'],
                    'activo' => (bool)$row['usuario_activo'] && (bool)$row['acceso_activo'],
                    'usuario_activo' => (bool)$row['usuario_activo'],
                    'acceso_activo' => (bool)$row['acceso_activo'],
                    'bloqueado_hasta' => $row['bloqueado_hasta'],
                    'ultimo_login_en' => $row['ultimo_login_en'],
                    'ultimo_acceso_en' => $row['ultimo_acceso_en'],
                    'creado_en' => $row['acceso_creado_en'],
                    'roles' => [],
                ];
            }

            if ($row['id_rol'] !== null) {
                $users[$accessId]['roles'][] = [
                    'id' => (int)$row['id_rol'],
                    'codigo' => (string)$row['rol_codigo'],
                    'nombre' => (string)$row['rol_nombre'],
                    'es_super_admin' => (bool)$row['es_super_admin'],
                ];
            }
        }

        return array_values(array_map(static function (array $user): array {
            $primaryRole = $user['roles'][0] ?? null;
            $user['rol'] = $primaryRole;
            $user['activo'] = $user['activo'] && $primaryRole !== null;
            return $user;
        }, $users));
    }

    public function roles(int $applicationId): array
    {
        $statement = $this->db()->prepare(
            'SELECT id_rol AS id, codigo, nombre, descripcion, es_super_admin
             FROM idn_roles
             WHERE id_aplicacion = :aplicacion AND activo = 1
             ORDER BY es_super_admin DESC, nombre ASC'
        );
        $statement->execute(['aplicacion' => $applicationId]);
        return array_values(array_map(static fn (array $row): array => [
            'id' => (int)$row['id'],
            'codigo' => (string)$row['codigo'],
            'nombre' => (string)$row['nombre'],
            'descripcion' => (string)($row['descripcion'] ?? ''),
            'es_super_admin' => (bool)$row['es_super_admin'],
        ], $statement->fetchAll() ?: []));
    }

    public function role(int $roleId, int $applicationId): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT id_rol AS id, codigo, nombre, descripcion, es_super_admin
             FROM idn_roles
             WHERE id_rol = :id
               AND id_aplicacion = :aplicacion
               AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $roleId, 'aplicacion' => $applicationId]);
        $row = $statement->fetch();
        if (!$row) return null;

        return [
            'id' => (int)$row['id'],
            'codigo' => (string)$row['codigo'],
            'nombre' => (string)$row['nombre'],
            'descripcion' => (string)($row['descripcion'] ?? ''),
            'es_super_admin' => (bool)$row['es_super_admin'],
        ];
    }

    public function access(int $accessId, int $applicationId): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT ua.id_usuario_aplicacion AS id_acceso,
                    ua.id_usuario,
                    ua.id_aplicacion,
                    ua.activo AS acceso_activo,
                    u.usuario,
                    u.nombre,
                    u.email,
                    u.activo AS usuario_activo,
                    r.id_rol,
                    r.codigo AS rol_codigo,
                    r.nombre AS rol_nombre,
                    r.es_super_admin
             FROM idn_usuarios_aplicaciones ua
             INNER JOIN idn_usuarios u ON u.id_usuario = ua.id_usuario
             LEFT JOIN idn_usuarios_roles ur
                    ON ur.id_usuario_aplicacion = ua.id_usuario_aplicacion
             LEFT JOIN idn_roles r
                    ON r.id_rol = ur.id_rol
                   AND r.id_aplicacion = ua.id_aplicacion
                   AND r.activo = 1
             WHERE ua.id_usuario_aplicacion = :acceso
               AND ua.id_aplicacion = :aplicacion
             ORDER BY r.es_super_admin DESC, r.nombre ASC
             LIMIT 1'
        );
        $statement->execute([
            'acceso' => $accessId,
            'aplicacion' => $applicationId,
        ]);
        $row = $statement->fetch();
        if (!$row) return null;

        return [
            'id_acceso' => (int)$row['id_acceso'],
            'id_usuario' => (int)$row['id_usuario'],
            'id_aplicacion' => (int)$row['id_aplicacion'],
            'usuario' => (string)$row['usuario'],
            'nombre' => (string)$row['nombre'],
            'email' => (string)($row['email'] ?? ''),
            'activo' => (bool)$row['usuario_activo'] && (bool)$row['acceso_activo'],
            'usuario_activo' => (bool)$row['usuario_activo'],
            'acceso_activo' => (bool)$row['acceso_activo'],
            'rol' => $row['id_rol'] === null ? null : [
                'id' => (int)$row['id_rol'],
                'codigo' => (string)$row['rol_codigo'],
                'nombre' => (string)$row['rol_nombre'],
                'es_super_admin' => (bool)$row['es_super_admin'],
            ],
        ];
    }

    public function usernameExists(string $username, ?int $excludeUserId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM idn_usuarios WHERE usuario = :usuario';
        $parameters = ['usuario' => $username];
        if ($excludeUserId !== null) {
            $sql .= ' AND id_usuario <> :excluir';
            $parameters['excluir'] = $excludeUserId;
        }
        $statement = $this->db()->prepare($sql);
        $statement->execute($parameters);
        return (int)$statement->fetchColumn() > 0;
    }

    public function emailExists(string $email, ?int $excludeUserId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM idn_usuarios WHERE email = :email';
        $parameters = ['email' => $email];
        if ($excludeUserId !== null) {
            $sql .= ' AND id_usuario <> :excluir';
            $parameters['excluir'] = $excludeUserId;
        }
        $statement = $this->db()->prepare($sql);
        $statement->execute($parameters);
        return (int)$statement->fetchColumn() > 0;
    }

    public function createUser(
        int $applicationId,
        int $roleId,
        string $username,
        string $name,
        ?string $email,
        string $passwordHash
    ): int {
        return IdentityConnection::transaction(static function (PDO $db) use (
            $applicationId,
            $roleId,
            $username,
            $name,
            $email,
            $passwordHash
        ): int {
            $user = $db->prepare(
                'INSERT INTO idn_usuarios
                 (usuario, nombre, email, hash_contrasena, activo,
                  debe_cambiar_contrasena, version_seguridad, creado_en, actualizado_en)
                 VALUES (:usuario, :nombre, :email, :hash, 1, 0, 1, NOW(), NOW())'
            );
            $user->execute([
                'usuario' => $username,
                'nombre' => $name,
                'email' => $email,
                'hash' => $passwordHash,
            ]);
            $userId = (int)$db->lastInsertId();

            $access = $db->prepare(
                'INSERT INTO idn_usuarios_aplicaciones
                 (id_usuario, id_aplicacion, tipo_perfil, activo, creado_en, actualizado_en)
                 VALUES (:usuario, :aplicacion, \'EMPLEADO\', 1, NOW(), NOW())'
            );
            $access->execute([
                'usuario' => $userId,
                'aplicacion' => $applicationId,
            ]);
            $accessId = (int)$db->lastInsertId();

            $db->prepare(
                'INSERT INTO idn_usuarios_roles
                 (id_usuario_aplicacion, id_rol, creado_en)
                 VALUES (:acceso, :rol, NOW())'
            )->execute(['acceso' => $accessId, 'rol' => $roleId]);

            return $accessId;
        });
    }

    public function updateUser(
        int $accessId,
        int $userId,
        int $roleId,
        string $username,
        string $name,
        ?string $email,
        ?string $passwordHash,
        bool $roleChanged
    ): void {
        IdentityConnection::transaction(static function (PDO $db) use (
            $accessId,
            $userId,
            $roleId,
            $username,
            $name,
            $email,
            $passwordHash,
            $roleChanged
        ): void {
            $sql = 'UPDATE idn_usuarios
                    SET usuario = :usuario,
                        nombre = :nombre,
                        email = :email,
                        actualizado_en = NOW()';
            $parameters = [
                'usuario' => $username,
                'nombre' => $name,
                'email' => $email,
                'id' => $userId,
            ];

            if ($passwordHash !== null) {
                $sql .= ', hash_contrasena = :hash,
                          version_seguridad = version_seguridad + 1,
                          bloqueado_hasta = NULL';
                $parameters['hash'] = $passwordHash;
            }
            $sql .= ' WHERE id_usuario = :id';

            $db->prepare($sql)->execute($parameters);

            if ($roleChanged) {
                $db->prepare(
                    'DELETE FROM idn_usuarios_roles WHERE id_usuario_aplicacion = :acceso'
                )->execute(['acceso' => $accessId]);
                $db->prepare(
                    'INSERT INTO idn_usuarios_roles
                     (id_usuario_aplicacion, id_rol, creado_en)
                     VALUES (:acceso, :rol, NOW())'
                )->execute(['acceso' => $accessId, 'rol' => $roleId]);
            }

            if ($passwordHash !== null) {
                $db->prepare(
                    'UPDATE idn_sesiones s
                     INNER JOIN idn_usuarios_aplicaciones ua
                             ON ua.id_usuario_aplicacion = s.id_usuario_aplicacion
                     SET s.activa = 0,
                         s.revocada_en = COALESCE(s.revocada_en, NOW()),
                         s.motivo_revocacion = COALESCE(s.motivo_revocacion, \'PASSWORD_CHANGED\')
                     WHERE ua.id_usuario = :usuario AND s.activa = 1'
                )->execute(['usuario' => $userId]);
            } elseif ($roleChanged) {
                self::revokeAccessSessions($db, $accessId, 'ROLE_CHANGED');
            }
        });
    }

    public function setAccessStatus(int $accessId, bool $active): void
    {
        IdentityConnection::transaction(static function (PDO $db) use ($accessId, $active): void {
            $db->prepare(
                'UPDATE idn_usuarios_aplicaciones
                 SET activo = :activo, actualizado_en = NOW()
                 WHERE id_usuario_aplicacion = :acceso'
            )->execute([
                'activo' => $active ? 1 : 0,
                'acceso' => $accessId,
            ]);

            if (!$active) {
                self::revokeAccessSessions($db, $accessId, 'APPLICATION_ACCESS_DISABLED');
            }
        });
    }

    public function deleteAccess(int $accessId): void
    {
        IdentityConnection::transaction(static function (PDO $db) use ($accessId): void {
            self::revokeAccessSessions($db, $accessId, 'APPLICATION_ACCESS_REMOVED');
            $db->prepare(
                'DELETE FROM idn_usuarios_aplicaciones WHERE id_usuario_aplicacion = :acceso'
            )->execute(['acceso' => $accessId]);
        });
    }

    public function activeSuperAdminCount(int $applicationId): int
    {
        $statement = $this->db()->prepare(
            'SELECT COUNT(DISTINCT ua.id_usuario_aplicacion)
             FROM idn_usuarios_aplicaciones ua
             INNER JOIN idn_usuarios u
                     ON u.id_usuario = ua.id_usuario AND u.activo = 1
             INNER JOIN idn_usuarios_roles ur
                     ON ur.id_usuario_aplicacion = ua.id_usuario_aplicacion
             INNER JOIN idn_roles r
                     ON r.id_rol = ur.id_rol
                    AND r.id_aplicacion = ua.id_aplicacion
                    AND r.es_super_admin = 1
                    AND r.activo = 1
             WHERE ua.id_aplicacion = :aplicacion AND ua.activo = 1'
        );
        $statement->execute(['aplicacion' => $applicationId]);
        return (int)$statement->fetchColumn();
    }

    private static function revokeAccessSessions(PDO $db, int $accessId, string $reason): void
    {
        $db->prepare(
            'UPDATE idn_sesiones
             SET activa = 0,
                 revocada_en = COALESCE(revocada_en, NOW()),
                 motivo_revocacion = COALESCE(motivo_revocacion, :motivo)
             WHERE id_usuario_aplicacion = :acceso AND activa = 1'
        )->execute([
            'acceso' => $accessId,
            'motivo' => $reason,
        ]);
    }
}
