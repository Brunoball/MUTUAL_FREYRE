<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Connection
{
    private const CHARACTER_SET = 'utf8mb4';
    private const COLLATION = 'utf8mb4_unicode_ci';

    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::int('DB_PORT', 3306);
        $name = Env::get('DB_NAME', 'mutual_freyre');
        $user = Env::get('DB_USER', 'root');
        $pass = Env::get('DB_PASS', '');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $name,
            self::CHARACTER_SET
        );

        self::$instance = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // MariaDB y MySQL pueden elegir colaciones de sesión diferentes aunque
        // el DSN indique utf8mb4. La fijamos explícitamente para que parámetros,
        // literales y columnas se comparen igual en local y en producción.
        self::$instance->exec(sprintf(
            'SET NAMES %s COLLATE %s',
            self::CHARACTER_SET,
            self::COLLATION
        ));

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
}