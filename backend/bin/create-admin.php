<?php
declare(strict_types=1);

use App\Core\Env;
use App\Core\Connection;

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

[$script, $username, $name, $password] = array_pad($argv, 4, null);
if (!$username || !$name || !$password) {
    fwrite(STDERR, "Uso: php bin/create-admin.php <usuario> \"<nombre>\" \"<contraseña>\"\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "La contraseña debe tener al menos 12 caracteres.\n");
    exit(1);
}
$algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash($password, $algorithm);
$db = Connection::get();
$roleId = $db->query("SELECT id_rol FROM sis_roles WHERE codigo = 'admin' LIMIT 1")->fetchColumn();
if (!$roleId) {
    fwrite(STDERR, "Primero importá la base mutual.sql.\n");
    exit(1);
}
$statement = $db->prepare(
    'INSERT INTO sis_usuarios (id_rol, usuario, nombre, hash_contrasena, activo, creado_en, actualizado_en)
     VALUES (:rol, :usuario, :nombre, :hash, 1, NOW(), NOW())
     ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), hash_contrasena = VALUES(hash_contrasena), id_rol = VALUES(id_rol), activo = 1, actualizado_en = NOW()'
);
$statement->execute(['rol' => $roleId, 'usuario' => $username, 'nombre' => $name, 'hash' => $hash]);
echo "Administrador creado o actualizado correctamente.\n";
