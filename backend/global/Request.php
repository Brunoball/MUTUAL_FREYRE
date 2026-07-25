<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private ?array $json = null;

    public function method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $normalized = '/' . trim($path, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    public function json(): array
    {
        if (is_array($this->json)) return $this->json;
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') return $this->json = $_POST ?: [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiException('El cuerpo JSON no es válido.', 'INVALID_JSON', 400);
        }
        return $this->json = $decoded;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return trim((string)($_SERVER[$key] ?? ''));
    }

    public function cookie(string $name): string
    {
        return trim((string)($_COOKIE[$name] ?? ''));
    }
}
