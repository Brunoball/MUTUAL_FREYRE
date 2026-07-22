<?php
declare(strict_types=1);

use App\Core\Env;
use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ClientContext;
use App\Core\Logger;

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set((string)Env::get('APP_TIMEZONE', 'America/Argentina/Cordoba'));

$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowed = array_values(array_filter(array_map('trim', explode(',', (string)Env::get('ALLOWED_ORIGINS', 'http://localhost:3000')))));
if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type, X-CSRF-Token, X-Correlation-ID');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$correlationId = ClientContext::correlationId();
header('X-Correlation-ID: ' . $correlationId);

try {
    /** @var App\Core\Router $router */
    $router = require dirname(__DIR__) . '/routes/api.php';
    $router->dispatch(new Request(), $correlationId);
} catch (ApiException $error) {
    Response::error($error, $correlationId);
} catch (Throwable $error) {
    Logger::error($error, $correlationId);
    $message = Env::bool('APP_DEBUG', false) ? $error->getMessage() : 'Error interno del servidor.';
    Response::error(new ApiException($message, 'INTERNAL_ERROR', 500), $correlationId);
}
