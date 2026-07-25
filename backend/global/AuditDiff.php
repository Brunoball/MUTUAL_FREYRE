<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Genera diferencias legibles y seguras para auditoría.
 * Nunca persiste contraseñas, hashes, tokens, cookies ni secretos.
 */
final class AuditDiff
{
    private const REDACTED = '[PROTEGIDO]';
    private const MAX_CHANGES = 500;
    private const MAX_STRING_LENGTH = 4000;

    /** @var array<string, true> */
    private const IGNORED_FIELDS = [
        'creado_en' => true,
        'actualizado_en' => true,
        'creado_por' => true,
        'actualizado_por' => true,
        'ultimo_uso_en' => true,
        'ultimo_acceso_en' => true,
        'ultimo_login_en' => true,
    ];

    public static function between(mixed $before, mixed $after): array
    {
        $before = self::sanitize($before);
        $after = self::sanitize($after);
        $changes = [];
        self::walk($before, $after, '', $changes);

        $total = count($changes);
        if ($total > self::MAX_CHANGES) {
            $changes = array_slice($changes, 0, self::MAX_CHANGES);
        }

        return [
            'cantidad' => $total,
            'truncado' => $total > self::MAX_CHANGES,
            'campos' => array_values($changes),
        ];
    }

    public static function snapshot(mixed $value): mixed
    {
        return self::sanitize($value);
    }

    private static function walk(mixed $before, mixed $after, string $path, array &$changes): void
    {
        if (count($changes) > self::MAX_CHANGES) {
            return;
        }

        if (is_array($before) && is_array($after)) {
            $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
            sort($keys);
            foreach ($keys as $key) {
                if (is_string($key) && isset(self::IGNORED_FIELDS[strtolower($key)])) {
                    continue;
                }
                $childPath = $path === '' ? (string)$key : $path . '.' . $key;
                $existsBefore = array_key_exists($key, $before);
                $existsAfter = array_key_exists($key, $after);
                self::walk(
                    $existsBefore ? $before[$key] : null,
                    $existsAfter ? $after[$key] : null,
                    $childPath,
                    $changes
                );
            }
            return;
        }

        if (self::same($before, $after)) {
            return;
        }

        $changes[] = [
            'campo' => $path === '' ? 'registro' : $path,
            'antes' => $before,
            'despues' => $after,
        ];
    }

    private static function same(mixed $before, mixed $after): bool
    {
        if (is_numeric($before) && is_numeric($after)) {
            return (string)(0 + $before) === (string)(0 + $after);
        }
        return $before === $after;
    }

    private static function sanitize(mixed $value, ?string $key = null, int $depth = 0): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return self::REDACTED;
        }
        if ($depth > 12) {
            return '[PROFUNDIDAD_LIMITADA]';
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) {
                if (is_string($childKey) && isset(self::IGNORED_FIELDS[strtolower($childKey)])) {
                    continue;
                }
                $result[$childKey] = self::sanitize($childValue, (string)$childKey, $depth + 1);
            }
            return $result;
        }
        if (is_object($value)) {
            return self::sanitize(get_object_vars($value), $key, $depth + 1);
        }
        if (is_string($value)) {
            if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                return mb_substr($value, 0, self::MAX_STRING_LENGTH) . '…';
            }
            return $value;
        }
        if (is_resource($value)) {
            return '[RECURSO]';
        }
        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (['contrasena', 'contraseña', 'password', 'passwd', 'hash', 'token', 'csrf', 'secret', 'cookie', 'session_hash'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    }
}
