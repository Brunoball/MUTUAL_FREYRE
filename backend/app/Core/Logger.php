<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function error(\Throwable $error, string $correlationId): void
    {
        $directory = dirname(__DIR__, 3) . '/storage/logs';
        if (!is_dir($directory)) @mkdir($directory, 0770, true);
        $line = sprintf("[%s] [%s] %s\n%s\n", date(DATE_ATOM), $correlationId, $error->getMessage(), $error->getTraceAsString());
        @file_put_contents($directory . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
