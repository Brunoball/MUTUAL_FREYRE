<?php
declare(strict_types=1);

/**
 * Autoload simple inspirado en Lerna.
 * - App\Core\*    => backend/global/
 * - App\Modules\* => backend/modules/
 */
spl_autoload_register(static function (string $class): void {
    $maps = [
        'App\\Core\\' => dirname(__DIR__) . '/global/',
        'App\\Modules\\' => dirname(__DIR__) . '/modules/',
    ];

    foreach ($maps as $prefix => $baseDirectory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDirectory . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
