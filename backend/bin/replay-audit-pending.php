<?php
declare(strict_types=1);

use App\Core\AuditLogger;
use App\Core\Env;

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set((string)Env::get('APP_TIMEZONE', 'America/Argentina/Cordoba'));

$directory = dirname(__DIR__) . '/storage/audit-pending';
if (!is_dir($directory)) {
    echo "No hay eventos pendientes de auditoría.\n";
    exit(0);
}

$files = glob($directory . '/*.json') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    echo "No hay eventos pendientes de auditoría.\n";
    exit(0);
}

$audit = new AuditLogger();
$processed = 0;
$failed = 0;

foreach ($files as $file) {
    try {
        $raw = file_get_contents($file);
        $payload = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($payload)) {
            throw new RuntimeException('Archivo pendiente inválido.');
        }

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $metadata['evento_encolado_en'] = $payload['queued_at'] ?? null;
        $metadata['error_original'] = $payload['original_error'] ?? null;
        $metadata['recuperado_desde_cola'] = true;

        $audit->record(
            isset($payload['user_id']) ? (int)$payload['user_id'] : null,
            (string)($payload['module'] ?? 'sistema'),
            (string)($payload['action'] ?? 'evento_pendiente'),
            isset($payload['entity']) ? (string)$payload['entity'] : null,
            $payload['entity_id'] ?? null,
            $metadata,
            (string)($payload['result'] ?? 'success'),
            isset($payload['correlation_id']) ? (string)$payload['correlation_id'] : null,
            is_array($payload['actor'] ?? null) ? $payload['actor'] : null,
            isset($payload['description']) ? (string)$payload['description'] : null,
            is_array($payload['changes'] ?? null) ? $payload['changes'] : []
        );

        if (!@unlink($file)) {
            throw new RuntimeException('El evento se insertó pero no se pudo borrar el archivo pendiente.');
        }
        $processed++;
        echo "OK: " . basename($file) . "\n";
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "ERROR " . basename($file) . ": {$error->getMessage()}\n");
        break; // Mantener el orden de la cadena criptográfica.
    }
}

echo "Procesados: {$processed}. Fallidos: {$failed}.\n";
exit($failed > 0 ? 1 : 0);
