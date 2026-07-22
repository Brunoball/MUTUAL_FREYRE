<?php
declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$publicFile = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($publicFile)) return false;
require __DIR__ . '/public/index.php';
