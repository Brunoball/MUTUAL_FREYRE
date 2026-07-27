<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Core\Env;
use App\Core\ExternalHttpClient;
use Closure;
use DateTimeImmutable;

/**
 * Control automático contra los JSON públicos oficiales de RePET.
 *
 * El resultado siempre es una preselección: incluso una coincidencia exacta
 * requiere revisión humana antes de confirmar identidad o tomar una decisión.
 */
final class RepetScreeningClient
{
    public const SOURCE = 'REPET';

    private const HOST = 'repet.jus.gob.ar';
    private const PERSONS_URL = 'https://repet.jus.gob.ar/xml/personas.json';
    private const ENTITIES_URL = 'https://repet.jus.gob.ar/xml/entidades.json';

    public function __construct(
        private readonly ?ExternalHttpClient $http = null,
        private readonly ?Closure $transport = null,
        private readonly ?string $cacheDirectory = null
    ) {}

    /**
     * @param array<int,array{nombre:string,rol?:string,tipo?:string}> $queries
     * @return array<string,mixed>
     */
    public function screen(array $queries, bool $force = false): array
    {
        $startedAt = microtime(true);
        $preparedQueries = $this->prepareQueries($queries);
        $consultedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');

        if ($preparedQueries === []) {
            return $this->sourceResult(
                'NO_DISPONIBLE',
                $consultedAt,
                [],
                [],
                false,
                'REPET_NAME_REQUIRED',
                'No hay una denominación o nombre confiable para controlar en RePET.',
                0
            );
        }

        $datasets = $this->datasets($force);
        if (!($datasets['available'] ?? false)) {
            return $this->sourceResult(
                'NO_DISPONIBLE',
                $consultedAt,
                $datasets['metadata'] ?? [],
                [],
                (bool)($datasets['from_cache'] ?? false),
                (string)($datasets['error_code'] ?? 'REPET_NOT_AVAILABLE'),
                (string)($datasets['error_message'] ?? 'RePET no está disponible.'),
                (int)round((microtime(true) - $startedAt) * 1000)
            );
        }

        $threshold = max(
            0.82,
            min(0.99, (float)Env::get('REPET_MATCH_THRESHOLD', '0.92'))
        );
        $screenings = [];
        $allMatches = [];
        foreach ($preparedQueries as $query) {
            $matches = [];
            $type = (string)$query['tipo'];
            if ($type !== 'ENTIDAD') {
                $matches = array_merge(
                    $matches,
                    $this->matchDataset(
                        (string)$query['nombre'],
                        (array)$datasets['personas'],
                        'PERSONA',
                        $threshold
                    )
                );
            }
            if ($type !== 'PERSONA') {
                $matches = array_merge(
                    $matches,
                    $this->matchDataset(
                        (string)$query['nombre'],
                        (array)$datasets['entidades'],
                        'ENTIDAD',
                        $threshold
                    )
                );
            }
            usort(
                $matches,
                static fn (array $left, array $right): int =>
                    ($right['puntaje'] <=> $left['puntaje'])
                    ?: strcmp((string)$left['nombre'], (string)$right['nombre'])
            );
            $matches = array_slice($matches, 0, 5);
            foreach ($matches as $match) {
                $allMatches[] = [
                    'consulta' => $query['nombre'],
                    'rol' => $query['rol'],
                    ...$match,
                ];
            }
            $screenings[] = [
                'nombre' => $query['nombre'],
                'rol' => $query['rol'],
                'tipo' => $type,
                'resultado' => $matches === []
                    ? 'SIN_COINCIDENCIA'
                    : 'COINCIDENCIA_POTENCIAL',
                'coincidencias' => $matches,
            ];
        }

        $state = (bool)($datasets['stale'] ?? false) ? 'VENCIDA' : 'OK';
        $result = $allMatches === []
            ? 'SIN_COINCIDENCIA'
            : 'COINCIDENCIA_POTENCIAL';
        $normal = [
            'resumen' => [
                'resultado' => $result,
                'requiere_revision_humana' => $allMatches !== [],
                'cantidad_nombres_controlados' => count($preparedQueries),
                'cantidad_coincidencias_potenciales' => count($allMatches),
                'umbral_similitud' => $threshold,
                'datos_vencidos' => (bool)($datasets['stale'] ?? false),
                'hash_personas' => $datasets['metadata']['personas']['hash_sha256'] ?? null,
                'hash_entidades' => $datasets['metadata']['entidades']['hash_sha256'] ?? null,
                'origen' => 'REPET_OFICIAL',
            ],
            'consultas' => $screenings,
            'coincidencias' => $allMatches,
        ];
        $rawMetadata = [
            'urls' => [
                'personas' => $this->personsUrl(),
                'entidades' => $this->entitiesUrl(),
            ],
            'datasets' => $datasets['metadata'],
            'metodo' => 'COMPARACION_LOCAL_SOBRE_JSON_OFICIAL',
            'resultado' => $result,
        ];

        return [
            'fuente' => self::SOURCE,
            'estado' => $state,
            'consultado_en' => $consultedAt,
            'periodo' => substr(
                (string)($datasets['metadata']['actualizado_en'] ?? $consultedAt),
                0,
                10
            ),
            'http_status' => (int)($datasets['http_status'] ?? 200),
            'respuesta' => $rawMetadata,
            'normalizado' => $normal,
            'hash_sha256' => hash('sha256', $this->encode([
                'datasets' => $datasets['metadata'],
                'consultas' => $screenings,
            ])),
            'error_codigo' => $state === 'VENCIDA' ? 'REPET_STALE_DATA' : null,
            'error_mensaje' => $state === 'VENCIDA'
                ? 'Se utilizó el último listado oficial conservado porque RePET no respondió. Debe actualizarse antes de un dictamen favorable.'
                : null,
            'duracion_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'es_cache' => (bool)($datasets['from_cache'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function datasets(bool $force): array
    {
        $ttlHours = max(1, min(168, Env::int('REPET_CACHE_HOURS', 12)));
        $cached = $this->readCache();
        if (
            !$force
            && ($cached['available'] ?? false)
            && (int)($cached['age_seconds'] ?? PHP_INT_MAX) <= $ttlHours * 3600
        ) {
            $cached['from_cache'] = true;
            $cached['stale'] = false;
            return $cached;
        }

        $urls = [
            'personas' => $this->personsUrl(),
            'entidades' => $this->entitiesUrl(),
        ];
        if ($this->transport instanceof Closure) {
            $responses = [];
            foreach ($urls as $key => $url) {
                $responses[$key] = ($this->transport)($key, $url);
            }
        } else {
            $timeout = max(5, min(30, Env::int('REPET_TIMEOUT_SECONDS', 15)));
            $responses = ($this->http ?? new ExternalHttpClient())->getManyJson(
                $urls,
                [self::HOST],
                [],
                $timeout,
                2_500_000
            );
        }

        $decoded = [];
        $rawBodies = [];
        $metadata = ['actualizado_en' => (new DateTimeImmutable())->format(DATE_ATOM)];
        $valid = true;
        $httpStatus = 200;
        foreach (['personas', 'entidades'] as $key) {
            $response = is_array($responses[$key] ?? null)
                ? $responses[$key]
                : [];
            $httpStatus = min(
                $httpStatus,
                max(0, (int)($response['http_status'] ?? 0))
            );
            $body = (string)($response['body'] ?? '');
            $data = json_decode($body, true);
            $minimum = $this->transport instanceof Closure
                ? 1
                : ($key === 'personas'
                    ? max(50, Env::int('REPET_MIN_PERSONAS', 100))
                    : max(10, Env::int('REPET_MIN_ENTIDADES', 20)));
            if (
                (string)($response['error_code'] ?? '') !== ''
                || (int)($response['http_status'] ?? 0) !== 200
                || !is_array($data)
                || count($data) < $minimum
            ) {
                $valid = false;
                continue;
            }
            $decoded[$key] = $data;
            $rawBodies[$key] = $body;
            $metadata[$key] = [
                'cantidad_registros' => count($data),
                'hash_sha256' => hash('sha256', $body),
                'url' => $urls[$key],
            ];
        }

        if ($valid && isset($decoded['personas'], $decoded['entidades'])) {
            $result = [
                'available' => true,
                'personas' => $decoded['personas'],
                'entidades' => $decoded['entidades'],
                'raw_bodies' => $rawBodies,
                'metadata' => $metadata,
                'from_cache' => false,
                'stale' => false,
                'http_status' => 200,
                'age_seconds' => 0,
            ];
            if (!($this->transport instanceof Closure)) {
                $this->writeCache($result);
            }
            return $result;
        }

        if ($cached['available'] ?? false) {
            $cached['from_cache'] = true;
            $cached['stale'] = true;
            $cached['http_status'] = $httpStatus;
            return $cached;
        }

        return [
            'available' => false,
            'metadata' => $metadata,
            'from_cache' => false,
            'stale' => false,
            'http_status' => $httpStatus,
            'error_code' => 'REPET_DATASET_NOT_AVAILABLE',
            'error_message' => 'No se pudieron obtener y validar los listados oficiales de personas y entidades de RePET.',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    private function matchDataset(
        string $query,
        array $records,
        string $recordType,
        float $threshold
    ): array {
        $queryNormalized = $this->normalizeName($query);
        if ($queryNormalized === '') {
            return [];
        }
        $matches = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $primaryName = $this->primaryName($record);
            $names = [$primaryName];
            $aliasKey = $recordType === 'PERSONA'
                ? 'INDIVIDUAL_ALIAS'
                : 'ENTITY_ALIAS';
            foreach ((array)($record[$aliasKey] ?? []) as $alias) {
                if (!is_array($alias)) {
                    continue;
                }
                foreach (preg_split('/\s*;\s*/u', (string)($alias['ALIAS_NAME'] ?? '')) ?: [] as $value) {
                    if (trim($value) !== '') {
                        $names[] = trim($value);
                    }
                }
            }
            if (trim((string)($record['NAME_ORIGINAL_SCRIPT'] ?? '')) !== '') {
                $names[] = trim((string)$record['NAME_ORIGINAL_SCRIPT']);
            }

            $best = 0.0;
            $matchedName = '';
            foreach (array_values(array_unique($names)) as $candidate) {
                $score = $this->similarity($queryNormalized, $this->normalizeName($candidate));
                if ($score > $best) {
                    $best = $score;
                    $matchedName = $candidate;
                }
            }
            if ($best < $threshold) {
                continue;
            }
            $matches[] = [
                'tipo_registro' => $recordType,
                'data_id' => (string)($record['DATAID'] ?? ''),
                'nombre' => $primaryName !== '' ? $primaryName : $matchedName,
                'nombre_coincidente' => $matchedName,
                'puntaje' => round($best, 4),
                'tipo_coincidencia' => $best >= 0.999 ? 'EXACTA' : 'SIMILAR',
                'referencia' => (string)($record['REFERENCE_NUMBER'] ?? ''),
                'lista' => (string)($record['UN_LIST_TYPE'] ?? $record['LIST_TYPE'] ?? ''),
                'fecha_inclusion' => (string)($record['LISTED_ON'] ?? ''),
            ];
        }
        return $matches;
    }

    private function primaryName(array $record): string
    {
        return trim(implode(' ', array_filter([
            trim((string)($record['FIRST_NAME'] ?? '')),
            trim((string)($record['SECOND_NAME'] ?? '')),
            trim((string)($record['THIRD_NAME'] ?? '')),
            trim((string)($record['FOURTH_NAME'] ?? '')),
        ], static fn (string $value): bool => $value !== '')));
    }

    private function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }
        if (hash_equals($left, $right)) {
            return 1.0;
        }

        $leftTokens = array_values(array_unique(explode(' ', $left)));
        $rightTokens = array_values(array_unique(explode(' ', $right)));
        sort($leftTokens);
        sort($rightTokens);
        if ($leftTokens === $rightTokens) {
            return 0.999;
        }
        if (count($leftTokens) < 2 || count($rightTokens) < 2) {
            return 0.0;
        }

        $intersection = count(array_intersect($leftTokens, $rightTokens));
        $union = count(array_unique(array_merge($leftTokens, $rightTokens)));
        $jaccard = $union > 0 ? $intersection / $union : 0.0;
        $containment = $intersection / max(1, min(count($leftTokens), count($rightTokens)));
        $maximumLength = max(strlen($left), strlen($right));
        $character = $maximumLength > 0
            ? 1 - min($maximumLength, levenshtein($left, $right)) / $maximumLength
            : 0.0;

        return max(0.0, min(
            1.0,
            ($jaccard * 0.5) + ($character * 0.35) + ($containment * 0.15)
        ));
    }

    /**
     * @param array<int,array{nombre:string,rol?:string,tipo?:string}> $queries
     * @return array<int,array{nombre:string,rol:string,tipo:string}>
     */
    private function prepareQueries(array $queries): array
    {
        $prepared = [];
        $seen = [];
        foreach ($queries as $query) {
            if (!is_array($query)) {
                continue;
            }
            $name = trim((string)($query['nombre'] ?? ''));
            $normalized = $this->normalizeName($name);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $type = strtoupper(trim((string)($query['tipo'] ?? 'AMBOS')));
            if (!in_array($type, ['PERSONA', 'ENTIDAD', 'AMBOS'], true)) {
                $type = 'AMBOS';
            }
            $seen[$normalized] = true;
            $prepared[] = [
                'nombre' => $name,
                'rol' => trim((string)($query['rol'] ?? 'PERSONA_CONSULTADA')),
                'tipo' => $type,
            ];
        }
        return $prepared;
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $value = strtr($value, [
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A',
            'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'Ñ' => 'N', 'Ç' => 'C',
        ]);
        $value = str_replace('.', '', $value);
        $value = preg_replace(
            [
                '/\bSOCIEDAD ANONIMA UNIPERSONAL\b/',
                '/\bSOCIEDAD ANONIMA\b/',
                '/\bSOCIEDAD DE RESPONSABILIDAD LIMITADA\b/',
            ],
            [' SAU ', ' SA ', ' SRL '],
            $value
        ) ?? $value;
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function readCache(): array
    {
        $directory = $this->resolvedCacheDirectory();
        $personsPath = $directory . '/personas.json';
        $entitiesPath = $directory . '/entidades.json';
        $metadataPath = $directory . '/metadata.json';
        if (!is_file($personsPath) || !is_file($entitiesPath) || !is_file($metadataPath)) {
            return ['available' => false];
        }
        $personsBody = file_get_contents($personsPath);
        $entitiesBody = file_get_contents($entitiesPath);
        $metadataBody = file_get_contents($metadataPath);
        if ($personsBody === false || $entitiesBody === false || $metadataBody === false) {
            return ['available' => false];
        }
        $persons = json_decode($personsBody, true);
        $entities = json_decode($entitiesBody, true);
        $metadata = json_decode($metadataBody, true);
        if (!is_array($persons) || !is_array($entities) || !is_array($metadata)) {
            return ['available' => false];
        }
        if (
            !hash_equals(
                (string)($metadata['personas']['hash_sha256'] ?? ''),
                hash('sha256', $personsBody)
            )
            || !hash_equals(
                (string)($metadata['entidades']['hash_sha256'] ?? ''),
                hash('sha256', $entitiesBody)
            )
        ) {
            return ['available' => false];
        }
        $updatedTimestamp = strtotime((string)($metadata['actualizado_en'] ?? '')) ?: 0;
        return [
            'available' => true,
            'personas' => $persons,
            'entidades' => $entities,
            'metadata' => $metadata,
            'from_cache' => true,
            'stale' => false,
            'http_status' => 200,
            'age_seconds' => max(0, time() - $updatedTimestamp),
        ];
    }

    private function writeCache(array $datasets): void
    {
        $directory = $this->resolvedCacheDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        $files = [
            'personas.json' => (string)(
                $datasets['raw_bodies']['personas']
                    ?? $this->encode($datasets['personas'])
            ),
            'entidades.json' => (string)(
                $datasets['raw_bodies']['entidades']
                    ?? $this->encode($datasets['entidades'])
            ),
            'metadata.json' => $this->encode($datasets['metadata']),
        ];
        foreach ($files as $name => $contents) {
            $temporary = tempnam($directory, 'repet_');
            if ($temporary === false) {
                return;
            }
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                @unlink($temporary);
                return;
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $directory . '/' . $name)) {
                @unlink($temporary);
                return;
            }
        }
    }

    private function resolvedCacheDirectory(): string
    {
        return $this->cacheDirectory
            ?? dirname(__DIR__, 2) . '/storage/private/repet';
    }

    private function personsUrl(): string
    {
        return (string)Env::get('REPET_PERSONAS_URL', self::PERSONS_URL);
    }

    private function entitiesUrl(): string
    {
        return (string)Env::get('REPET_ENTIDADES_URL', self::ENTITIES_URL);
    }

    private function sourceResult(
        string $state,
        string $consultedAt,
        array $metadata,
        array $normal,
        bool $fromCache,
        ?string $errorCode,
        ?string $errorMessage,
        int $durationMs
    ): array {
        return [
            'fuente' => self::SOURCE,
            'estado' => $state,
            'consultado_en' => $consultedAt,
            'periodo' => null,
            'http_status' => null,
            'respuesta' => ['datasets' => $metadata],
            'normalizado' => $normal,
            'hash_sha256' => hash('sha256', $this->encode($metadata)),
            'error_codigo' => $errorCode,
            'error_mensaje' => $errorMessage,
            'duracion_ms' => $durationMs,
            'es_cache' => $fromCache,
        ];
    }

    private function encode(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return is_string($json) ? $json : '{}';
    }
}
