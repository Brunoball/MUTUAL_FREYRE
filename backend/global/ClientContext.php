<?php
declare(strict_types=1);

namespace App\Core;

final class ClientContext
{
    public static function ip(): string
    {
        return substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
    }

    public static function userAgent(): string
    {
        return substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    }

    public static function correlationId(): string
    {
        $provided = trim((string)($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''));
        if ($provided !== '' && preg_match('/^[A-Za-z0-9._-]{8,80}$/', $provided)) return $provided;
        return bin2hex(random_bytes(12));
    }
}
