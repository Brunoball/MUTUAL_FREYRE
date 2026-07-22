<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Auth\AuthService;

final class Router
{
    private array $routes = [];

    public function __construct(private readonly AuthService $auth) {}

    public function get(string $path, callable $handler, array $options = []): void { $this->add('GET', $path, $handler, $options); }
    public function post(string $path, callable $handler, array $options = []): void { $this->add('POST', $path, $handler, $options); }
    public function put(string $path, callable $handler, array $options = []): void { $this->add('PUT', $path, $handler, $options); }
    public function patch(string $path, callable $handler, array $options = []): void { $this->add('PATCH', $path, $handler, $options); }
    public function delete(string $path, callable $handler, array $options = []): void { $this->add('DELETE', $path, $handler, $options); }

    public function dispatch(Request $request, string $correlationId): never
    {
        $key = $request->method() . ' ' . $request->path();
        if (!isset($this->routes[$key])) {
            throw new ApiException('Ruta no encontrada.', 'ROUTE_NOT_FOUND', 404);
        }
        $route = $this->routes[$key];
        $session = null;
        if (($route['auth'] ?? true) === true) {
            $session = $this->auth->authenticate($request);
            if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                $this->auth->assertCsrf($request, $session);
            }
            $this->auth->assertPermission($session, $route['permission'] ?? null);
        }
        ($route['handler'])($request, $session, $correlationId);
        Response::success();
    }

    private function add(string $method, string $path, callable $handler, array $options): void
    {
        $normalized = '/' . trim($path, '/');
        $this->routes[strtoupper($method) . ' ' . $normalized] = [
            'handler' => $handler,
            'auth' => $options['auth'] ?? true,
            'permission' => $options['permission'] ?? null,
        ];
    }
}
