<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Core\Env;
use App\Core\ExternalHttpClient;
use Closure;
use DateTimeImmutable;

final class BcraCentralDeudoresClient
{
    public const SOURCE_CURRENT = 'BCRA_DEUDA_ACTUAL';
    public const SOURCE_HISTORY = 'BCRA_HISTORICO';
    public const SOURCE_CHEQUES = 'BCRA_CHEQUES_RECHAZADOS';

    private const HOST = 'api.bcra.gob.ar';
    private const BASE_URL = 'https://api.bcra.gob.ar/CentralDeDeudores/v1.0/Deudas';

    /** @var array<string,string> */
    private const PATHS = [
        self::SOURCE_CURRENT => '',
        self::SOURCE_HISTORY => '/Historicas',
        self::SOURCE_CHEQUES => '/ChequesRechazados',
    ];

    public function __construct(
        private readonly ?ExternalHttpClient $http = null,
        private readonly ?Closure $transport = null
    ) {}

    /**
     * @return array<string,array<string,mixed>>
     */
    public function queryAll(string $identification): array
    {
        $identification = CuitValidator::validate($identification);
        $timeout = max(3, min(12, Env::int('BCRA_TIMEOUT_SECONDS', 7)));
        $rawResponses = [];

        if ($this->transport instanceof Closure) {
            foreach (self::PATHS as $source => $path) {
                $rawResponses[$source] = ($this->transport)(
                    $source,
                    self::BASE_URL . $path . '/' . $identification
                );
            }
        } else {
            $urls = [];
            foreach (self::PATHS as $source => $path) {
                $urls[$source] = self::BASE_URL . $path . '/' . $identification;
            }
            $rawResponses = ($this->http ?? new ExternalHttpClient())->getManyJson(
                $urls,
                [self::HOST],
                [],
                $timeout
            );

            foreach ($rawResponses as $source => $response) {
                if (!$this->isTransient($response)) {
                    continue;
                }
                $rawResponses[$source] = ($this->http ?? new ExternalHttpClient())->getJson(
                    $urls[$source],
                    [self::HOST],
                    [],
                    $timeout
                );
            }
        }

        $normalized = [];
        foreach (self::PATHS as $source => $_path) {
            $response = is_array($rawResponses[$source] ?? null)
                ? $rawResponses[$source]
                : $this->emptyTransportResponse();
            $normalized[$source] = $this->normalizeResponse(
                $source,
                $response
            );
        }
        return $normalized;
    }

    private function normalizeResponse(string $source, array $response): array
    {
        $consultedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $httpStatus = (int)($response['http_status'] ?? 0);
        $body = (string)($response['body'] ?? '');
        $transportError = trim((string)($response['error_code'] ?? ''));
        $durationMs = max(0, (int)($response['duration_ms'] ?? 0));

        if ($transportError !== '') {
            return $this->sourceResult(
                $source,
                'NO_DISPONIBLE',
                $consultedAt,
                $httpStatus,
                [],
                [],
                null,
                $transportError,
                (string)($response['error_message'] ?? 'Fuente no disponible.'),
                $durationMs
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $this->sourceResult(
                $source,
                $httpStatus >= 500 || $httpStatus === 429 ? 'NO_DISPONIBLE' : 'ERROR',
                $consultedAt,
                $httpStatus,
                [],
                [],
                null,
                'BCRA_INVALID_JSON',
                'El BCRA devolvió una respuesta que no pudo interpretarse.',
                $durationMs
            );
        }

        if ($httpStatus === 404 || (int)($decoded['status'] ?? 0) === 404) {
            return $this->sourceResult(
                $source,
                'SIN_DATOS',
                $consultedAt,
                404,
                $decoded,
                $this->emptyNormalized($source),
                null,
                null,
                null,
                $durationMs
            );
        }

        if ($httpStatus !== 200 || (int)($decoded['status'] ?? 200) !== 200) {
            $state = $httpStatus === 429 || $httpStatus >= 500 || $httpStatus === 0
                ? 'NO_DISPONIBLE'
                : 'ERROR';
            return $this->sourceResult(
                $source,
                $state,
                $consultedAt,
                $httpStatus,
                $decoded,
                [],
                null,
                'BCRA_HTTP_' . ($httpStatus ?: 'UNKNOWN'),
                $this->externalMessage($decoded, $state),
                $durationMs
            );
        }

        $results = $decoded['results'] ?? null;
        if (!is_array($results)) {
            return $this->sourceResult(
                $source,
                'ERROR',
                $consultedAt,
                200,
                $decoded,
                [],
                null,
                'BCRA_CONTRACT_ERROR',
                'La respuesta del BCRA no contiene el contrato esperado.',
                $durationMs
            );
        }

        $normalizer = match ($source) {
            self::SOURCE_CURRENT => 'normalizeCurrent',
            self::SOURCE_HISTORY => 'normalizeHistory',
            self::SOURCE_CHEQUES => 'normalizeCheques',
            default => null,
        };
        $normal = $normalizer !== null ? $this->{$normalizer}($results) : [];
        $period = isset($normal['resumen']['periodo'])
            ? (string)$normal['resumen']['periodo']
            : null;

        return $this->sourceResult(
            $source,
            'OK',
            $consultedAt,
            200,
            $decoded,
            $normal,
            $period,
            null,
            null,
            $durationMs
        );
    }

    private function normalizeCurrent(array $results): array
    {
        $periods = is_array($results['periodos'] ?? null) ? $results['periodos'] : [];
        $latest = is_array($periods[0] ?? null) ? $periods[0] : [];
        $entities = [];
        $totalThousands = 0.0;
        $worstSituation = 0;
        $maximumDelay = 0;
        $judicial = false;
        $inReview = false;

        foreach ((array)($latest['entidades'] ?? []) as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $amountThousands = round((float)($entity['monto'] ?? 0), 2);
            $situation = max(0, (int)($entity['situacion'] ?? 0));
            $delay = max(0, (int)($entity['diasAtrasoPago'] ?? 0));
            $totalThousands += $amountThousands;
            $worstSituation = max($worstSituation, $situation);
            $maximumDelay = max($maximumDelay, $delay);
            $judicial = $judicial
                || (bool)($entity['procesoJud'] ?? false)
                || (bool)($entity['situacionJuridica'] ?? false);
            $inReview = $inReview || (bool)($entity['enRevision'] ?? false);

            $entities[] = [
                'entidad' => (string)($entity['entidad'] ?? 'SIN DENOMINACIÓN'),
                'situacion' => $situation,
                'situacion_descripcion' => self::situationLabel($situation),
                'fecha_situacion_1' => $entity['fechaSit1'] ?? null,
                'monto_miles_pesos' => $amountThousands,
                'monto_pesos_estimados' => round($amountThousands * 1000, 2),
                'dias_atraso' => $delay,
                'refinanciaciones' => (bool)($entity['refinanciaciones'] ?? false),
                'recategorizacion_obligatoria' => (bool)($entity['recategorizacionOblig'] ?? false),
                'situacion_juridica' => (bool)($entity['situacionJuridica'] ?? false),
                'irrecuperable_disposicion_tecnica' => (bool)($entity['irrecDisposicionTecnica'] ?? false),
                'en_revision' => (bool)($entity['enRevision'] ?? false),
                'proceso_judicial' => (bool)($entity['procesoJud'] ?? false),
            ];
        }

        return [
            'resumen' => [
                'identificacion' => (string)($results['identificacion'] ?? ''),
                'denominacion' => (string)($results['denominacion'] ?? ''),
                'periodo' => (string)($latest['periodo'] ?? ''),
                'cantidad_entidades' => count($entities),
                'peor_situacion' => $worstSituation ?: null,
                'peor_situacion_descripcion' => self::situationLabel($worstSituation),
                'deuda_total_miles_pesos' => round($totalThousands, 2),
                'deuda_total_pesos_estimada' => round($totalThousands * 1000, 2),
                'monto_unidad_fuente' => 'MILES_DE_PESOS',
                'dias_atraso_maximo' => $maximumDelay,
                'tiene_proceso_judicial' => $judicial,
                'tiene_datos_en_revision' => $inReview,
            ],
            'entidades' => $entities,
        ];
    }

    private function normalizeHistory(array $results): array
    {
        $normalizedPeriods = [];
        $worstSituation = 0;
        $maximumDebtThousands = 0.0;

        foreach ((array)($results['periodos'] ?? []) as $period) {
            if (!is_array($period)) {
                continue;
            }
            $entities = [];
            $totalThousands = 0.0;
            $periodWorst = 0;
            foreach ((array)($period['entidades'] ?? []) as $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                $amountThousands = round((float)($entity['monto'] ?? 0), 2);
                $situation = max(0, (int)($entity['situacion'] ?? 0));
                $totalThousands += $amountThousands;
                $periodWorst = max($periodWorst, $situation);
                $worstSituation = max($worstSituation, $situation);
                $entities[] = [
                    'entidad' => (string)($entity['entidad'] ?? 'SIN DENOMINACIÓN'),
                    'situacion' => $situation,
                    'situacion_descripcion' => self::situationLabel($situation),
                    'monto_miles_pesos' => $amountThousands,
                    'monto_pesos_estimados' => round($amountThousands * 1000, 2),
                    'en_revision' => (bool)($entity['enRevision'] ?? false),
                    'proceso_judicial' => (bool)($entity['procesoJud'] ?? false),
                ];
            }
            $maximumDebtThousands = max($maximumDebtThousands, $totalThousands);
            $normalizedPeriods[] = [
                'periodo' => (string)($period['periodo'] ?? ''),
                'peor_situacion' => $periodWorst ?: null,
                'peor_situacion_descripcion' => self::situationLabel($periodWorst),
                'deuda_total_miles_pesos' => round($totalThousands, 2),
                'deuda_total_pesos_estimada' => round($totalThousands * 1000, 2),
                'entidades' => $entities,
            ];
        }

        return [
            'resumen' => [
                'identificacion' => (string)($results['identificacion'] ?? ''),
                'denominacion' => (string)($results['denominacion'] ?? ''),
                'cantidad_periodos' => count($normalizedPeriods),
                'peor_situacion_24_meses' => $worstSituation ?: null,
                'peor_situacion_descripcion' => self::situationLabel($worstSituation),
                'deuda_maxima_miles_pesos' => round($maximumDebtThousands, 2),
                'deuda_maxima_pesos_estimada' => round($maximumDebtThousands * 1000, 2),
                'monto_unidad_fuente' => 'MILES_DE_PESOS',
            ],
            'periodos' => $normalizedPeriods,
        ];
    }

    private function normalizeCheques(array $results): array
    {
        $cheques = [];
        $unpaid = 0;
        $unpaidAmount = 0.0;
        $totalAmount = 0.0;

        foreach ((array)($results['causales'] ?? []) as $causal) {
            if (!is_array($causal)) {
                continue;
            }
            foreach ((array)($causal['entidades'] ?? []) as $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                foreach ((array)($entity['detalle'] ?? []) as $detail) {
                    if (!is_array($detail)) {
                        continue;
                    }
                    $amount = round((float)($detail['monto'] ?? 0), 2);
                    $isUnpaid = empty($detail['fechaPago']);
                    $totalAmount += $amount;
                    if ($isUnpaid) {
                        $unpaid++;
                        $unpaidAmount += $amount;
                    }
                    $cheques[] = [
                        'causal' => (string)($causal['causal'] ?? 'SIN CAUSAL'),
                        'entidad_codigo' => $entity['entidad'] ?? null,
                        'numero_cheque' => (string)($detail['nroCheque'] ?? ''),
                        'fecha_rechazo' => $detail['fechaRechazo'] ?? null,
                        'monto_pesos' => $amount,
                        'fecha_pago' => $detail['fechaPago'] ?? null,
                        'fecha_pago_multa' => $detail['fechaPagoMulta'] ?? null,
                        'estado_multa' => $detail['estadoMulta'] ?? null,
                        'cuenta_personal' => (bool)($detail['ctaPersonal'] ?? false),
                        'denominacion_juridica' => $detail['denomJuridica'] ?? null,
                        'en_revision' => (bool)($detail['enRevision'] ?? false),
                        'proceso_judicial' => (bool)($detail['procesoJud'] ?? false),
                    ];
                }
            }
        }

        return [
            'resumen' => [
                'identificacion' => (string)($results['identificacion'] ?? ''),
                'denominacion' => (string)($results['denominacion'] ?? ''),
                'cantidad_rechazados' => count($cheques),
                'cantidad_pendientes_pago' => $unpaid,
                'monto_total_pesos' => round($totalAmount, 2),
                'monto_pendiente_pesos' => round($unpaidAmount, 2),
            ],
            'cheques' => $cheques,
        ];
    }

    private function emptyNormalized(string $source): array
    {
        return match ($source) {
            self::SOURCE_CURRENT => [
                'resumen' => [
                    'cantidad_entidades' => 0,
                    'peor_situacion' => null,
                    'deuda_total_miles_pesos' => 0,
                    'deuda_total_pesos_estimada' => 0,
                    'monto_unidad_fuente' => 'MILES_DE_PESOS',
                    'dias_atraso_maximo' => 0,
                    'tiene_proceso_judicial' => false,
                ],
                'entidades' => [],
            ],
            self::SOURCE_HISTORY => [
                'resumen' => [
                    'cantidad_periodos' => 0,
                    'peor_situacion_24_meses' => null,
                    'deuda_maxima_miles_pesos' => 0,
                    'deuda_maxima_pesos_estimada' => 0,
                    'monto_unidad_fuente' => 'MILES_DE_PESOS',
                ],
                'periodos' => [],
            ],
            self::SOURCE_CHEQUES => [
                'resumen' => [
                    'cantidad_rechazados' => 0,
                    'cantidad_pendientes_pago' => 0,
                    'monto_total_pesos' => 0,
                    'monto_pendiente_pesos' => 0,
                ],
                'cheques' => [],
            ],
            default => ['resumen' => []],
        };
    }

    private function sourceResult(
        string $source,
        string $state,
        string $consultedAt,
        int $httpStatus,
        array $raw,
        array $normalized,
        ?string $period,
        ?string $errorCode,
        ?string $errorMessage,
        int $durationMs
    ): array {
        $rawJson = json_encode(
            $raw,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}';

        return [
            'fuente' => $source,
            'estado' => $state,
            'consultado_en' => $consultedAt,
            'periodo' => $period,
            'http_status' => $httpStatus ?: null,
            'respuesta' => $raw,
            'normalizado' => $normalized,
            'hash_sha256' => hash('sha256', $rawJson),
            'error_codigo' => $errorCode,
            'error_mensaje' => $errorMessage !== null
                ? mb_substr($errorMessage, 0, 500)
                : null,
            'duracion_ms' => $durationMs,
        ];
    }

    private function externalMessage(array $decoded, string $state): string
    {
        $messages = is_array($decoded['errorMessages'] ?? null)
            ? $decoded['errorMessages']
            : [];
        $message = trim(implode(' ', array_map('strval', $messages)));
        if ($message !== '') {
            return mb_substr($message, 0, 500);
        }
        return $state === 'NO_DISPONIBLE'
            ? 'La fuente BCRA no está disponible temporalmente.'
            : 'El BCRA rechazó la consulta.';
    }

    private function isTransient(array $response): bool
    {
        $status = (int)($response['http_status'] ?? 0);
        return !empty($response['error_code'])
            || $status === 0
            || $status === 429
            || $status >= 500;
    }

    private function emptyTransportResponse(): array
    {
        return [
            'http_status' => 0,
            'body' => '',
            'duration_ms' => 0,
            'error_code' => 'BCRA_NO_RESPONSE',
            'error_message' => 'No se recibió una respuesta del BCRA.',
        ];
    }

    public static function situationLabel(int $situation): string
    {
        return match ($situation) {
            1 => 'Situación normal',
            2 => 'Seguimiento especial / riesgo bajo',
            3 => 'Con problemas / riesgo medio',
            4 => 'Alto riesgo de insolvencia / riesgo alto',
            5 => 'Irrecuperable',
            6 => 'Irrecuperable por disposición técnica',
            default => 'Sin clasificación',
        };
    }
}

