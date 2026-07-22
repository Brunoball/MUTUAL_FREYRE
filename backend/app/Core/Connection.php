<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Env;
use PDO;

final class Connection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance instanceof PDO) return self::$instance;
        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::int('DB_PORT', 3306);
        $name = Env::get('DB_NAME', 'mutual_freyre');
        $user = Env::get('DB_USER', 'root');
        $pass = Env::get('DB_PASS', 'Gastex2233');
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
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
