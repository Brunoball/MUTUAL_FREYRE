<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Conexión exclusiva para identidad y autenticación.
 *
 * Mantiene usuarios, accesos por aplicación, roles, permisos y sesiones
 * fuera de la base financiera principal. Si una variable AUTH_DB_* no está
 * definida o queda vacía, reutiliza la equivalente DB_* del sistema interno.
 */
final class IdentityConnection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host = self::valueOrFallback('AUTH_DB_HOST', 'DB_HOST', 'localhost');
        $port = (int)self::valueOrFallback('AUTH_DB_PORT', 'DB_PORT', '3306');
        $name = self::valueOrFallback('AUTH_DB_NAME', null, 'mutual_identidad');
        $user = self::valueOrFallback('AUTH_DB_USER', 'DB_USER', 'root');
        $pass = self::valueOrFallback('AUTH_DB_PASS', 'DB_PASS', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        self::$instance = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        return self::$instance;
    }

    public static function transaction(callable $callback): mixed
    {
        $db = self::get();
        $db->beginTransaction();
        try {
            $result = $callback($db);
            $db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    private static function valueOrFallback(
        string $primaryKey,
        ?string $fallbackKey,
        string $default
    ): string {
        $value = trim((string)Env::get($primaryKey, ''));
        if ($value !== '') {
            return $value;
        }

        if ($fallbackKey !== null) {
            $fallback = trim((string)Env::get($fallbackKey, ''));
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return $default;
    }
}
