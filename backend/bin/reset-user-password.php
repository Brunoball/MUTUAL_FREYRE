<?php
declare(strict_types=1);

use App\Core\AuditDiff;
use App\Core\AuditLogger;
use App\Core\Env;
use App\Core\IdentityConnection;

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set((string)Env::get('APP_TIMEZONE', 'America/Argentina/Cordoba'));

[$script, $credential, $newPassword] = array_pad($argv, 3, null);
$credential = trim((string)$credential);
$newPassword = (string)$newPassword;

if ($credential === '' || $newPassword === '') {
    fwrite(STDERR, "Uso: php bin/reset-user-password.php <usuario-o-email> \"<nueva-contraseña>\"\n");
    exit(1);
}
if (mb_strlen($credential) > 180) {
    fwrite(STDERR, "El usuario o correo supera la longitud permitida.\n");
    exit(1);
}
if (strlen($newPassword) < 12) {
    fwrite(STDERR, "La nueva contraseña debe tener al menos 12 caracteres.\n");
    exit(1);
}

$algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash($newPassword, $algorithm);
if (!is_string($hash)) {
    fwrite(STDERR, "No se pudo generar el hash de la contraseña.\n");
    exit(1);
}

$applicationCode = trim((string)Env::get('AUTH_APPLICATION_CODE', 'backoffice')) ?: 'backoffice';
$correlationId = 'cli-' . bin2hex(random_bytes(12));
$audit = new AuditLogger();

try {
    $result = IdentityConnection::transaction(static function (\PDO $db) use (
        $credential,
        $hash,
        $applicationCode,
        $correlationId
    ): array {
        $statement = $db->prepare(
            'SELECT u.id_usuario, u.usuario, u.nombre, u.email, u.activo,
                    u.bloqueado_hasta, u.version_seguridad,
                    ua.id_usuario_aplicacion, ua.activo AS acceso_activo,
                    a.id_aplicacion, a.codigo AS aplicacion_codigo,
                    r.codigo AS rol_codigo, r.nombre AS rol_nombre
             FROM idn_usuarios u
             INNER JOIN idn_usuarios_aplicaciones ua ON ua.id_usuario = u.id_usuario
             INNER JOIN idn_aplicaciones a ON a.id_aplicacion = ua.id_aplicacion
             LEFT JOIN idn_usuarios_roles ur ON ur.id_usuario_aplicacion = ua.id_usuario_aplicacion
             LEFT JOIN idn_roles r ON r.id_rol = ur.id_rol AND r.activo = 1
             WHERE (u.usuario = :usuario OR u.email = :email)
               AND a.codigo = :aplicacion
             ORDER BY r.es_super_admin DESC, r.nombre ASC
             LIMIT 1'
        );
        $statement->execute([
            'usuario' => $credential,
            'email' => $credential,
            'aplicacion' => $applicationCode,
        ]);
        $before = $statement->fetch();
        if (!$before) {
            throw new RuntimeException("No existe un usuario con acceso a {$applicationCode} para la credencial indicada.");
        }
        if (empty($before['rol_codigo'])) {
            throw new RuntimeException('El usuario existe pero no tiene un rol activo asignado. Corregí el rol antes de ingresar.');
        }

        $userId = (int)$before['id_usuario'];
        $accessId = (int)$before['id_usuario_aplicacion'];
        $applicationId = (int)$before['id_aplicacion'];

        $db->prepare(
            'UPDATE idn_usuarios
             SET hash_contrasena = :hash,
                 activo = 1,
                 bloqueado_hasta = NULL,
                 debe_cambiar_contrasena = 0,
                 version_seguridad = version_seguridad + 1,
                 actualizado_en = NOW()
             WHERE id_usuario = :usuario'
        )->execute(['hash' => $hash, 'usuario' => $userId]);

        $db->prepare(
            'UPDATE idn_usuarios_aplicaciones
             SET activo = 1, actualizado_en = NOW()
             WHERE id_usuario_aplicacion = :acceso'
        )->execute(['acceso' => $accessId]);

        $db->prepare(
            'UPDATE idn_sesiones
             SET activa = 0,
                 revocada_en = COALESCE(revocada_en, NOW()),
                 motivo_revocacion = COALESCE(motivo_revocacion, \'PASSWORD_RESET_CLI\')
             WHERE id_usuario_aplicacion = :acceso AND activa = 1'
        )->execute(['acceso' => $accessId]);

        // Un intento exitoso de recuperación corta el contador de fallos sin borrar historial.
        $db->prepare(
            'INSERT INTO idn_login_intentos
             (id_aplicacion, id_usuario, credencial, ip, user_agent, exito, motivo, creado_en)
             VALUES (:aplicacion, :usuario, :credencial, :ip, :user_agent, 1, :motivo, NOW())'
        )->execute([
            'aplicacion' => $applicationId,
            'usuario' => $userId,
            'credencial' => mb_substr((string)$before['usuario'], 0, 180),
            'ip' => 'CLI',
            'user_agent' => 'bin/reset-user-password.php',
            'motivo' => 'PASSWORD_RESET_CLI',
        ]);

        $db->prepare(
            'INSERT INTO idn_eventos_seguridad
             (id_aplicacion, id_usuario, evento, resultado, metadata, ip, user_agent, correlation_id, creado_en)
             VALUES (:aplicacion, :usuario, :evento, :resultado, :metadata, :ip, :user_agent, :correlation_id, NOW())'
        )->execute([
            'aplicacion' => $applicationId,
            'usuario' => $userId,
            'evento' => 'password_reset_cli',
            'resultado' => 'success',
            'metadata' => json_encode([
                'usuario' => $before['usuario'],
                'aplicacion' => $applicationCode,
                'sesiones_revocadas' => true,
                'bloqueo_limpiado' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => 'CLI',
            'user_agent' => 'bin/reset-user-password.php',
            'correlation_id' => $correlationId,
        ]);

        $after = $before;
        $after['activo'] = 1;
        $after['bloqueado_hasta'] = null;
        $after['acceso_activo'] = 1;
        $after['version_seguridad'] = (int)$before['version_seguridad'] + 1;

        return [
            'before' => $before,
            'after' => $after,
            'user_id' => $userId,
            'access_id' => $accessId,
        ];
    });

    $changes = AuditDiff::between($result['before'], $result['after']);
    $changes['cantidad'] = (int)($changes['cantidad'] ?? 0) + 1;
    $changes['campos'][] = [
        'campo' => 'seguridad.contrasena',
        'antes' => '[NO EXPUESTA]',
        'despues' => '[RESTABLECIDA]',
    ];

    $auditId = $audit->recordSafely(
        null,
        'configuracion',
        'restablecer_contrasena_cli',
        'usuario_aplicacion',
        (int)$result['access_id'],
        [
            'origen' => 'bin/reset-user-password.php',
            'usuario_objetivo_id' => (int)$result['user_id'],
            'sesiones_revocadas' => true,
            'bloqueo_limpiado' => true,
            'snapshot_anterior' => AuditDiff::snapshot($result['before']),
            'snapshot_final' => AuditDiff::snapshot($result['after']),
        ],
        'success',
        $correlationId,
        [
            'usuario' => 'cli-recuperacion-login',
            'nombre' => 'Herramienta de recuperación de acceso',
            'rol' => 'SISTEMA',
            'aplicacion_codigo' => $applicationCode,
        ],
        "Restableció la contraseña de {$result['after']['usuario']}, reactivó su acceso y revocó las sesiones anteriores.",
        $changes
    );

    echo "Acceso restablecido correctamente para {$result['after']['usuario']}.\n";
    echo "Se reactivó el usuario, se limpió el bloqueo y se revocaron las sesiones anteriores.\n";
    echo $auditId !== null
        ? "Evento de auditoría generado: #{$auditId}.\n"
        : "Advertencia: el acceso quedó restablecido, pero revisá storage/logs/app.log porque no se pudo escribir la auditoría general.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Error: {$error->getMessage()}\n");
    exit(1);
}
