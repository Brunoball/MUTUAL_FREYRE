<?php
declare(strict_types=1);

use App\Core\Env;
use App\Core\IdentityConnection;

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

[$script, $username, $name, $password] = array_pad($argv, 4, null);
if (!$username || !$name || !$password) {
    fwrite(STDERR, "Uso: php bin/create-admin.php <usuario> \"<nombre>\" \"<contraseña>\"\n");
    exit(1);
}
if (mb_strlen((string)$username) > 100 || mb_strlen((string)$name) > 160) {
    fwrite(STDERR, "El usuario o el nombre superan la longitud permitida.\n");
    exit(1);
}
if (strlen((string)$password) < 12) {
    fwrite(STDERR, "La contraseña debe tener al menos 12 caracteres.\n");
    exit(1);
}

$applicationCode = trim((string)Env::get('AUTH_APPLICATION_CODE', 'backoffice'));
$algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash((string)$password, $algorithm);
if (!is_string($hash)) {
    fwrite(STDERR, "No se pudo generar el hash de la contraseña.\n");
    exit(1);
}

try {
    IdentityConnection::transaction(static function (\PDO $db) use (
        $username,
        $name,
        $hash,
        $applicationCode
    ): void {
        $application = $db->prepare(
            'SELECT id_aplicacion FROM idn_aplicaciones WHERE codigo = :codigo LIMIT 1'
        );
        $application->execute(['codigo' => $applicationCode]);
        $applicationId = (int)$application->fetchColumn();
        if ($applicationId <= 0) {
            throw new RuntimeException(
                "La aplicación {$applicationCode} no existe. Ejecutá primero el SQL de identidad."
            );
        }

        $role = $db->prepare(
            'SELECT id_rol
             FROM idn_roles
             WHERE id_aplicacion = :aplicacion AND codigo = \'admin\' AND activo = 1
             LIMIT 1'
        );
        $role->execute(['aplicacion' => $applicationId]);
        $roleId = (int)$role->fetchColumn();
        if ($roleId <= 0) {
            throw new RuntimeException('No existe el rol admin para la aplicación configurada.');
        }

        $userStatement = $db->prepare(
            'INSERT INTO idn_usuarios
             (usuario, nombre, hash_contrasena, activo, debe_cambiar_contrasena,
              version_seguridad, creado_en, actualizado_en)
             VALUES (:usuario, :nombre, :hash, 1, 0, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
               nombre = VALUES(nombre),
               hash_contrasena = VALUES(hash_contrasena),
               activo = 1,
               bloqueado_hasta = NULL,
               version_seguridad = version_seguridad + 1,
               actualizado_en = NOW()'
        );
        $userStatement->execute([
            'usuario' => trim((string)$username),
            'nombre' => trim((string)$name),
            'hash' => $hash,
        ]);

        $findUser = $db->prepare(
            'SELECT id_usuario FROM idn_usuarios WHERE usuario = :usuario LIMIT 1'
        );
        $findUser->execute(['usuario' => trim((string)$username)]);
        $userId = (int)$findUser->fetchColumn();
        if ($userId <= 0) {
            throw new RuntimeException('No se pudo recuperar el usuario creado.');
        }

        $access = $db->prepare(
            'INSERT INTO idn_usuarios_aplicaciones
             (id_usuario, id_aplicacion, tipo_perfil, activo, creado_en, actualizado_en)
             VALUES (:usuario, :aplicacion, \'EMPLEADO\', 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
               activo = 1,
               tipo_perfil = \'EMPLEADO\',
               actualizado_en = NOW()'
        );
        $access->execute([
            'usuario' => $userId,
            'aplicacion' => $applicationId,
        ]);

        $findAccess = $db->prepare(
            'SELECT id_usuario_aplicacion
             FROM idn_usuarios_aplicaciones
             WHERE id_usuario = :usuario AND id_aplicacion = :aplicacion
             LIMIT 1'
        );
        $findAccess->execute([
            'usuario' => $userId,
            'aplicacion' => $applicationId,
        ]);
        $accessId = (int)$findAccess->fetchColumn();
        if ($accessId <= 0) {
            throw new RuntimeException('No se pudo crear el acceso del administrador.');
        }

        $db->prepare(
            'INSERT IGNORE INTO idn_usuarios_roles
             (id_usuario_aplicacion, id_rol, creado_en)
             VALUES (:acceso, :rol, NOW())'
        )->execute([
            'acceso' => $accessId,
            'rol' => $roleId,
        ]);

        $db->prepare(
            'UPDATE idn_sesiones s
             INNER JOIN idn_usuarios_aplicaciones ua
                     ON ua.id_usuario_aplicacion = s.id_usuario_aplicacion
             SET s.activa = 0,
                 s.revocada_en = COALESCE(s.revocada_en, NOW()),
                 s.motivo_revocacion = COALESCE(s.motivo_revocacion, \'PASSWORD_CHANGED\')
             WHERE ua.id_usuario = :usuario AND s.activa = 1'
        )->execute(['usuario' => $userId]);
    });

    echo "Administrador creado o actualizado correctamente en mutual_identidad.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Error: {$error->getMessage()}\n");
    exit(1);
}
