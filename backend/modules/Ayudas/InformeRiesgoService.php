<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Core\ApiException;
use App\Core\AuditLogger;
use App\Core\Connection;
use App\Core\Env;
use DateTimeImmutable;
use PDO;

final class InformeRiesgoService
{
    private const BCRA_SOURCES = [
        BcraCentralDeudoresClient::SOURCE_CURRENT,
        BcraCentralDeudoresClient::SOURCE_HISTORY,
        BcraCentralDeudoresClient::SOURCE_CHEQUES,
    ];

    public function __construct(
        private readonly InformeRiesgoRepository $repository,
        private readonly BcraCentralDeudoresClient $bcra,
        private readonly RepetScreeningClient $repet,
        private readonly RiesgoUifEngine $uifEngine,
        private readonly AuditLogger $audit
    ) {}

    public function list(array $filters): array
    {
        $cuit = trim((string)($filters['cuit'] ?? ''));
        if ($cuit !== '') {
            $cuit = CuitValidator::validate($cuit, 'cuit');
        }
        $personId = filter_var(
            $filters['id_persona'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        return [
            'items' => $this->repository->list([
                'cuit' => $cuit !== '' ? $cuit : null,
                'id_persona' => $personId ?: null,
                'limite' => $filters['limite'] ?? 30,
            ]),
        ];
    }

    public function detail(int $reportId): array
    {
        $detail = $this->repository->detail($reportId);
        if (!$detail) {
            throw new ApiException(
                'El informe solicitado no existe.',
                'RISK_REPORT_NOT_FOUND',
                404
            );
        }
        return $detail;
    }

    public function generate(
        array $input,
        array $session,
        string $correlationId
    ): array {
        $userId = $this->userId($session);
        $personId = filter_var(
            $input['id_persona'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $person = $personId ? $this->repository->personById((int)$personId) : null;
        if ($personId && !$person) {
            throw new ApiException(
                'La persona seleccionada no existe.',
                'PERSON_NOT_FOUND',
                404
            );
        }

        $providedCuit = trim((string)($input['cuit'] ?? ''));
        $resolvedCuit = $providedCuit !== ''
            ? CuitValidator::validate($providedCuit, 'cuit')
            : CuitValidator::validate((string)($person['cuit_cuil'] ?? ''), 'cuit');
        if (
            $person
            && !empty($person['cuit_cuil'])
            && $resolvedCuit !== (string)$person['cuit_cuil']
        ) {
            throw new ApiException(
                'El CUIT ingresado no corresponde a la persona seleccionada.',
                'PERSON_IDENTIFICATION_MISMATCH',
                422,
                ['cuit' => 'No coincide con el legajo seleccionado.']
            );
        }

        if (!$person) {
            $person = $this->repository->personByCuit($resolvedCuit);
            $personId = $person ? (int)$person['id_persona'] : null;
        }

        $background = $this->repository->internalBackground(
            $personId ? (int)$personId : null,
            $resolvedCuit
        );
        $inputDenomination = $person
            ? ''
            : trim((string)($input['denominacion'] ?? ''));
        if (mb_strlen($inputDenomination) > 240) {
            throw new ApiException(
                'La denominación supera la longitud permitida.',
                'INVALID_DENOMINATION',
                422,
                ['denominacion' => 'Ingresá hasta 240 caracteres.']
            );
        }
        $denomination = trim((string)($person['nombre_exhibicion'] ?? ''));
        if ($denomination === '') {
            $denomination = $inputDenomination;
        }
        $reportId = $this->repository->createReport([
            'id_persona' => $personId ?: null,
            'cuit' => $resolvedCuit,
            'denominacion' => $denomination !== '' ? $denomination : null,
            'antecedentes' => $background,
            'resumen' => [
                'generado_en' => (new DateTimeImmutable())->format(DATE_ATOM),
                'fuentes' => [],
            ],
            'correlation_id' => $correlationId,
            'creado_por' => $userId,
        ]);

        $bcraSources = $this->resolveBcraSources($resolvedCuit, false);
        $risk = $this->creditRisk($bcraSources);
        $bcraDenomination = $this->bcraDenomination($bcraSources);
        $resolvedDenomination = trim((string)$bcraDenomination) !== ''
            ? trim((string)$bcraDenomination)
            : $denomination;
        $repetSource = $this->resolveRepetSource(
            $resolvedDenomination,
            $person,
            $background,
            false
        );
        $sources = [...$bcraSources, $repetSource];
        $summary = $this->buildSummary($sources, $background, $risk);
        $integrityHash = $this->reportHash($reportId, $summary, $sources);

        Connection::transaction(function (PDO $db) use (
            $reportId,
            $sources,
            $risk,
            $summary,
            $integrityHash,
            $userId,
            $resolvedDenomination,
            $session,
            $correlationId,
            $resolvedCuit
        ): void {
            foreach ($sources as $source) {
                $this->repository->insertSource(
                    $reportId,
                    $source,
                    (bool)($source['es_cache'] ?? false)
                );
            }
            $this->repository->updateReport(
                $reportId,
                'PENDIENTE_DATOS',
                $risk,
                null,
                null,
                $summary,
                $integrityHash,
                $userId,
                $resolvedDenomination !== '' ? $resolvedDenomination : null
            );
            $this->audit->recordForSession(
                $session,
                'ayudas_informes',
                'generar',
                'informe_riesgo',
                $reportId,
                'Generó un informe integral por CUIT con controles BCRA y RePET.',
                [
                    [
                        'campo' => 'informe.estado',
                        'antes' => null,
                        'despues' => 'PENDIENTE_DATOS',
                    ],
                    [
                        'campo' => 'informe.riesgo_crediticio',
                        'antes' => null,
                        'despues' => $risk,
                    ],
                ],
                [
                    'cuit_enmascarado' => CuitValidator::mask($resolvedCuit),
                    'fuentes' => array_map(
                        static fn (array $source): array => [
                            'fuente' => $source['fuente'],
                            'estado' => $source['estado'],
                            'hash' => $source['hash_sha256'] ?? null,
                            'cache' => (bool)($source['es_cache'] ?? false),
                        ],
                        $sources
                    ),
                ],
                'success',
                $correlationId,
                $db
            );
        });

        return $this->detail($reportId);
    }

    public function refreshBcra(
        int $reportId,
        array $session,
        string $correlationId
    ): array {
        $report = $this->requireReport($reportId);
        $userId = $this->userId($session);
        $bcraSources = $this->resolveBcraSources((string)$report['cuit_cuil'], true);
        $risk = $this->creditRisk($bcraSources);
        $background = $this->decode((string)($report['antecedentes_json'] ?? ''));
        $sources = $this->mergeWithLatestNonBcraSources($reportId, $bcraSources);
        $summary = $this->buildSummary(
            $sources,
            $background,
            $risk,
            (string)$report['riesgo_uif']
        );
        $integrityHash = $this->reportHash($reportId, $summary, $sources);
        $nextState = (string)$report['estado'] === 'DICTAMINADO'
            ? 'OBSERVADO'
            : ((string)$report['estado'] === 'GENERANDO'
                ? 'PENDIENTE_DATOS'
                : (string)$report['estado']);

        Connection::transaction(function (PDO $db) use (
            $reportId,
            $report,
            $bcraSources,
            $risk,
            $summary,
            $integrityHash,
            $userId,
            $nextState,
            $session,
            $correlationId
        ): void {
            foreach ($bcraSources as $source) {
                $this->repository->insertSource($reportId, $source, false);
            }
            $this->repository->updateReport(
                $reportId,
                $nextState,
                $risk,
                null,
                null,
                $summary,
                $integrityHash,
                $userId,
                $this->bcraDenomination($bcraSources)
            );
            $this->audit->recordForSession(
                $session,
                'ayudas_informes',
                'refrescar_bcra',
                'informe_riesgo',
                $reportId,
                'Actualizó de forma forzada las fuentes BCRA del informe.',
                [
                    [
                        'campo' => 'informe.riesgo_crediticio',
                        'antes' => $report['riesgo_crediticio'],
                        'despues' => $risk,
                    ],
                    [
                        'campo' => 'informe.estado',
                        'antes' => $report['estado'],
                        'despues' => $nextState,
                    ],
                ],
                [
                    'cuit_enmascarado' => CuitValidator::mask((string)$report['cuit_cuil']),
                    'actualizacion_forzada' => true,
                ],
                'success',
                $correlationId,
                $db
            );
        });

        return $this->detail($reportId);
    }

    public function refreshRepet(
        int $reportId,
        array $session,
        string $correlationId
    ): array {
        $report = $this->requireMutableReport($reportId);
        $userId = $this->userId($session);
        $background = $this->decode((string)($report['antecedentes_json'] ?? ''));
        $person = is_array($background['persona'] ?? null)
            ? $background['persona']
            : null;
        $repetSource = $this->resolveRepetSource(
            trim((string)($report['denominacion'] ?? '')),
            $person,
            $background,
            true
        );
        $sources = $this->mergeWithLatestBcraSources($reportId, $repetSource);
        $summary = $this->buildSummary(
            $sources,
            $background,
            (string)$report['riesgo_crediticio'],
            (string)$report['riesgo_uif']
        );
        $integrityHash = $this->reportHash($reportId, $summary, $sources);
        $nextState = (string)$report['estado'] === 'DICTAMINADO'
            ? 'OBSERVADO'
            : (string)$report['estado'];

        Connection::transaction(function (PDO $db) use (
            $reportId,
            $report,
            $repetSource,
            $summary,
            $integrityHash,
            $userId,
            $nextState,
            $session,
            $correlationId
        ): void {
            $this->repository->insertSource($reportId, $repetSource, false);
            $this->repository->updateReport(
                $reportId,
                $nextState,
                (string)$report['riesgo_crediticio'],
                null,
                null,
                $summary,
                $integrityHash,
                $userId
            );
            $this->audit->recordForSession(
                $session,
                'ayudas_informes',
                'refrescar_repet',
                'informe_riesgo',
                $reportId,
                'Actualizó de forma forzada el control automático RePET.',
                [[
                    'campo' => 'fuente.repet.estado',
                    'antes' => null,
                    'despues' => $repetSource['estado'],
                ]],
                [
                    'cuit_enmascarado' => CuitValidator::mask((string)$report['cuit_cuil']),
                    'resultado' => $repetSource['normalizado']['resumen']['resultado']
                        ?? 'PENDIENTE',
                    'actualizacion_forzada' => true,
                ],
                'success',
                $correlationId,
                $db
            );
        });

        return $this->detail($reportId);
    }

    public function saveEvaluation(
        int $reportId,
        array $payload,
        array $session,
        string $correlationId
    ): array {
        $report = $this->requireMutableReport($reportId);
        $userId = $this->userId($session);
        $repetSource = $this->latestRepetSource($reportId);
        $payload['terrorismo_resultado'] = $this->repetResultForEvaluation(
            $repetSource
        );
        $input = $this->evaluationInput($payload);
        $background = $this->decode((string)($report['antecedentes_json'] ?? ''));
        $person = is_array($background['persona'] ?? null)
            ? $background['persona']
            : [];
        $context = [
            'persona_es_pep' => (bool)($person['es_pep'] ?? false),
            'vinculos_pep' => (int)($background['vinculos_pep'] ?? 0),
            'antecedentes_resumen' => $background['ayudas'] ?? [],
            'repet' => $repetSource,
        ];
        $result = $this->uifEngine->evaluate(
            $input,
            $context,
            $this->repository->rules()
        );
        $documentationState = $this->documentationState($input, $result);
        $nextState = $result['tiene_alerta_critica'] || $result['nivel_riesgo'] === 'ALTO'
            ? 'OBSERVADO'
            : 'LISTO';

        Connection::transaction(function (PDO $db) use (
            $reportId,
            $report,
            $input,
            $result,
            $documentationState,
            $nextState,
            $userId,
            $session,
            $correlationId
        ): void {
            $version = $this->repository->nextEvaluationVersion($reportId);
            $evaluationId = $this->repository->insertEvaluation(
                $reportId,
                $version,
                $input,
                $result,
                $userId
            );
            $this->repository->insertAlerts(
                $reportId,
                $evaluationId,
                $result['alertas']
            );
            $this->repository->updateEvaluationResult(
                $reportId,
                $result['nivel_riesgo'],
                $documentationState,
                $nextState,
                $userId
            );
            $this->audit->recordForSession(
                $session,
                'ayudas_informes',
                'evaluar_uif',
                'informe_riesgo',
                $reportId,
                'Guardó una evaluación preliminar LA/FT y recalculó sus alertas.',
                [
                    [
                        'campo' => 'informe.riesgo_uif',
                        'antes' => $report['riesgo_uif'],
                        'despues' => $result['nivel_riesgo'],
                    ],
                    [
                        'campo' => 'informe.documentacion_estado',
                        'antes' => $report['documentacion_estado'],
                        'despues' => $documentationState,
                    ],
                ],
                [
                    'cuit_enmascarado' => CuitValidator::mask((string)$report['cuit_cuil']),
                    'version_reglas' => $result['version_reglas'],
                    'cantidad_alertas' => count($result['alertas']),
                    'alerta_critica' => (bool)$result['tiene_alerta_critica'],
                ],
                'success',
                $correlationId,
                $db
            );
        });

        return $this->detail($reportId);
    }

    public function dictate(
        int $reportId,
        array $payload,
        array $session,
        string $correlationId
    ): array {
        $report = $this->requireMutableReport($reportId);
        $evaluation = $this->repository->latestEvaluation($reportId);
        if (!$evaluation) {
            throw new ApiException(
                'Completá la evaluación UIF antes de emitir el dictamen.',
                'UIF_EVALUATION_REQUIRED',
                422
            );
        }
        $repetSource = $this->latestRepetSource($reportId);

        $result = strtoupper(trim((string)($payload['resultado'] ?? '')));
        $allowed = [
            'RECOMENDADO',
            'CONDICIONADO',
            'REVISION',
            'NO_RECOMENDADO',
            'NO_CONTINUAR_DD',
        ];
        if (!in_array($result, $allowed, true)) {
            throw new ApiException(
                'Seleccioná un dictamen válido.',
                'INVALID_DECISION',
                422,
                ['resultado' => 'Resultado no permitido.']
            );
        }

        $basis = $this->optionalText($payload['fundamento'] ?? null, 4000);
        $conditions = $this->optionalText($payload['condiciones'] ?? null, 4000);
        if ($basis === null || mb_strlen($basis) < 10) {
            throw new ApiException(
                'Ingresá un fundamento claro para el dictamen.',
                'DECISION_BASIS_REQUIRED',
                422,
                ['fundamento' => 'Debe contener al menos 10 caracteres.']
            );
        }
        if (
            $result === 'CONDICIONADO'
            && ($conditions === null || mb_strlen($conditions) < 5)
        ) {
            throw new ApiException(
                'Detallá las condiciones que deben cumplirse.',
                'DECISION_CONDITIONS_REQUIRED',
                422,
                ['condiciones' => 'Las condiciones son obligatorias.']
            );
        }
        if (
            $result === 'RECOMENDADO'
            && in_array(
                (string)$report['riesgo_crediticio'],
                ['SIN_INFORMACION', 'NO_DETERMINADO', 'ALTO'],
                true
            )
        ) {
            throw new ApiException(
                'No se puede recomendar el caso con riesgo crediticio alto, no determinado o sin información.',
                'CREDIT_RISK_REVIEW_REQUIRED',
                422
            );
        }
        if (
            $result === 'RECOMENDADO'
            && (
                (string)$report['riesgo_uif'] === 'ALTO'
                || (string)$report['documentacion_estado'] !== 'COMPLETA'
            )
        ) {
            throw new ApiException(
                'No se puede recomendar el caso mientras el riesgo LA/FT sea alto o la documentación esté incompleta.',
                'ENHANCED_REVIEW_REQUIRED',
                422
            );
        }
        if (
            $result === 'RECOMENDADO'
            && (
                !$repetSource
                || (string)$repetSource['estado'] !== 'OK'
                || $this->repetResultForEvaluation($repetSource)
                    !== 'SIN_COINCIDENCIA'
            )
        ) {
            throw new ApiException(
                'No se puede recomendar el caso sin un control RePET vigente y sin coincidencias.',
                'REPET_REVIEW_REQUIRED',
                422
            );
        }

        $hasCritical = $this->repository->hasCriticalAlert($reportId);
        $compliancePermission = $this->hasPermission(
            $session,
            InformeRiesgoPolicy::COMPLIANCE
        );
        if ($hasCritical && !$compliancePermission) {
            throw new ApiException(
                'El informe contiene una alerta crítica y requiere intervención del área de Cumplimiento.',
                'COMPLIANCE_REVIEW_REQUIRED',
                403
            );
        }

        $userId = $this->userId($session);
        Connection::transaction(function (PDO $db) use (
            $reportId,
            $report,
            $result,
            $conditions,
            $basis,
            $compliancePermission,
            $userId,
            $session,
            $correlationId,
            $hasCritical
        ): void {
            $decisionId = $this->repository->insertDictamen(
                $reportId,
                $result,
                $conditions,
                $basis,
                $compliancePermission,
                $userId
            );
            $this->audit->recordForSession(
                $session,
                'ayudas_informes',
                'dictaminar',
                'informe_riesgo',
                $reportId,
                'Emitió el dictamen humano del informe integral.',
                [
                    [
                        'campo' => 'informe.estado',
                        'antes' => $report['estado'],
                        'despues' => 'DICTAMINADO',
                    ],
                    [
                        'campo' => 'dictamen.resultado',
                        'antes' => null,
                        'despues' => $result,
                    ],
                ],
                [
                    'id_dictamen' => $decisionId,
                    'cuit_enmascarado' => CuitValidator::mask((string)$report['cuit_cuil']),
                    'revision_cumplimiento' => $compliancePermission,
                    'existia_alerta_critica' => $hasCritical,
                ],
                'success',
                $correlationId,
                $db
            );
        });

        return $this->detail($reportId);
    }

    public function evidence(int $documentId): array
    {
        $document = $this->repository->document($documentId);
        if (!$document) {
            throw new ApiException(
                'La evidencia solicitada no existe.',
                'EVIDENCE_NOT_FOUND',
                404
            );
        }
        $storageRoot = realpath(dirname(__DIR__, 2) . '/storage/private');
        $absolute = realpath(
            dirname(__DIR__, 2) . '/storage/private/' . ltrim(
                (string)$document['ruta_privada'],
                '/'
            )
        );
        if (
            $storageRoot === false
            || $absolute === false
            || !str_starts_with($absolute, $storageRoot . DIRECTORY_SEPARATOR)
            || !is_file($absolute)
        ) {
            throw new ApiException(
                'El archivo de evidencia no está disponible.',
                'EVIDENCE_FILE_NOT_AVAILABLE',
                404
            );
        }
        return [
            'path' => $absolute,
            'name' => (string)$document['nombre_original'],
            'mime' => (string)$document['mime'],
            'size' => (int)$document['tamano_bytes'],
            'hash' => (string)$document['hash_sha256'],
        ];
    }

    private function resolveBcraSources(string $cuit, bool $force): array
    {
        $ttlMinutes = max(5, min(1440, Env::int('BCRA_CACHE_MINUTES', 360)));
        $since = (new DateTimeImmutable())
            ->modify('-' . $ttlMinutes . ' minutes')
            ->format('Y-m-d H:i:s.u');
        $sources = [];
        $missing = [];

        foreach (self::BCRA_SOURCES as $sourceName) {
            $cached = $force
                ? null
                : $this->repository->cachedSource($cuit, $sourceName, $since);
            if ($cached) {
                $cached['es_cache'] = true;
                $sources[$sourceName] = $cached;
            } else {
                $missing[] = $sourceName;
            }
        }

        if ($missing !== []) {
            $queried = $this->bcra->queryAll($cuit);
            foreach ($missing as $sourceName) {
                $source = $queried[$sourceName] ?? [
                    'fuente' => $sourceName,
                    'estado' => 'NO_DISPONIBLE',
                    'consultado_en' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                    'periodo' => null,
                    'http_status' => null,
                    'respuesta' => [],
                    'normalizado' => [],
                    'hash_sha256' => hash('sha256', '{}'),
                    'error_codigo' => 'BCRA_MISSING_SOURCE',
                    'error_mensaje' => 'No se recibió una respuesta para esta fuente.',
                    'duracion_ms' => null,
                ];
                $source['es_cache'] = false;
                $sources[$sourceName] = $source;
            }
        }

        return array_values(array_map(
            static fn (string $sourceName): array => $sources[$sourceName],
            self::BCRA_SOURCES
        ));
    }

    private function resolveRepetSource(
        string $denomination,
        ?array $person,
        array $background,
        bool $force
    ): array {
        $queries = [];
        if (trim($denomination) !== '') {
            $personType = strtoupper((string)($person['tipo_persona'] ?? ''));
            $queries[] = [
                'nombre' => $denomination,
                'rol' => 'PERSONA_CONSULTADA',
                'tipo' => $personType === 'FISICA'
                    ? 'PERSONA'
                    : ($personType === 'JURIDICA' ? 'ENTIDAD' : 'AMBOS'),
            ];
        }
        foreach ((array)($background['vinculos'] ?? []) as $link) {
            if (!is_array($link) || trim((string)($link['nombre'] ?? '')) === '') {
                continue;
            }
            $queries[] = [
                'nombre' => (string)$link['nombre'],
                'rol' => 'VINCULO_' . strtoupper((string)($link['tipo'] ?? 'RELACIONADO')),
                'tipo' => 'AMBOS',
            ];
        }
        return $this->repet->screen($queries, $force);
    }

    private function mergeWithLatestNonBcraSources(
        int $reportId,
        array $bcraSources
    ): array {
        $sources = $bcraSources;
        $repet = $this->latestRepetSource($reportId);
        if ($repet) {
            $sources[] = $repet;
        }
        return $sources;
    }

    private function mergeWithLatestBcraSources(
        int $reportId,
        array $repetSource
    ): array {
        $sources = [];
        $latest = $this->repository->latestSources($reportId);
        foreach (self::BCRA_SOURCES as $sourceName) {
            foreach ($latest as $source) {
                if ((string)($source['fuente'] ?? '') === $sourceName) {
                    $sources[] = $source;
                    break;
                }
            }
        }
        $sources[] = $repetSource;
        return $sources;
    }

    private function latestRepetSource(int $reportId): ?array
    {
        foreach ($this->repository->latestSources($reportId) as $source) {
            if ((string)($source['fuente'] ?? '') === RepetScreeningClient::SOURCE) {
                return $source;
            }
        }
        return null;
    }

    private function repetResultForEvaluation(?array $source): string
    {
        if (!$source) {
            return 'PENDIENTE';
        }
        $result = strtoupper((string)(
            $source['normalizado']['resumen']['resultado'] ?? 'PENDIENTE'
        ));
        if ($result === 'COINCIDENCIA_POTENCIAL') {
            return $result;
        }
        return (string)($source['estado'] ?? '') === 'OK'
            && $result === 'SIN_COINCIDENCIA'
            ? 'SIN_COINCIDENCIA'
            : 'PENDIENTE';
    }

    private function creditRisk(array $sources): string
    {
        $indexed = [];
        foreach ($sources as $source) {
            $indexed[$source['fuente']] = $source;
        }
        $current = $indexed[BcraCentralDeudoresClient::SOURCE_CURRENT] ?? [];
        $history = $indexed[BcraCentralDeudoresClient::SOURCE_HISTORY] ?? [];
        $cheques = $indexed[BcraCentralDeudoresClient::SOURCE_CHEQUES] ?? [];
        $currentSummary = $current['normalizado']['resumen'] ?? [];
        $historySummary = $history['normalizado']['resumen'] ?? [];
        $chequeSummary = $cheques['normalizado']['resumen'] ?? [];

        $worstCurrent = (int)($currentSummary['peor_situacion'] ?? 0);
        $worstHistory = (int)($historySummary['peor_situacion_24_meses'] ?? 0);
        $unpaidCheques = (int)($chequeSummary['cantidad_pendientes_pago'] ?? 0);
        $rejectedCheques = (int)($chequeSummary['cantidad_rechazados'] ?? 0);
        $judicial = (bool)($currentSummary['tiene_proceso_judicial'] ?? false);

        if ($unpaidCheques > 0 || $judicial || max($worstCurrent, $worstHistory) >= 4) {
            return 'ALTO';
        }
        if ($rejectedCheques > 0 || max($worstCurrent, $worstHistory) >= 2) {
            return 'MEDIO';
        }

        $states = array_map(
            static fn (array $source): string => (string)($source['estado'] ?? 'ERROR'),
            $sources
        );
        if (count(array_filter(
            $states,
            static fn (string $state): bool => $state === 'SIN_DATOS'
        )) === count($states)) {
            return 'SIN_INFORMACION';
        }
        $allUsable = count(array_filter(
            $states,
            static fn (string $state): bool => in_array(
                $state,
                ['OK', 'SIN_DATOS'],
                true
            )
        )) === count($states);
        if ($allUsable) {
            return 'BAJO';
        }
        return 'NO_DETERMINADO';
    }

    private function buildSummary(
        array $sources,
        array $background,
        string $creditRisk,
        string $uifRisk = 'NO_DETERMINADO'
    ): array {
        $sourceSummary = [];
        foreach ($sources as $source) {
            $sourceSummary[$source['fuente']] = [
                'estado' => $source['estado'],
                'consultado_en' => $source['consultado_en'],
                'periodo' => $source['periodo'] ?? null,
                'hash_sha256' => $source['hash_sha256'] ?? null,
                'es_cache' => (bool)($source['es_cache'] ?? false),
                'resumen' => $source['normalizado']['resumen'] ?? [],
            ];
        }
        return [
            'generado_en' => (new DateTimeImmutable())->format(DATE_ATOM),
            'riesgo_crediticio' => $creditRisk,
            'riesgo_uif' => $uifRisk,
            'fuentes' => $sourceSummary,
            'antecedentes_internos' => [
                'persona_encontrada' => (bool)($background['persona_encontrada'] ?? false),
                'ayudas' => $background['ayudas'] ?? [],
                'documentos' => $background['documentos'] ?? [],
                'cantidad_vinculos' => count((array)($background['vinculos'] ?? [])),
                'vinculos_pep' => (int)($background['vinculos_pep'] ?? 0),
            ],
            'separacion_riesgos' => true,
            'advertencia' => 'La información BCRA es crediticia. La evaluación UIF/LA-FT se calcula por separado y requiere intervención humana.',
        ];
    }

    private function evaluationInput(array $payload): array
    {
        $pep = strtoupper(trim((string)($payload['pep_estado'] ?? 'NO_INFORMA')));
        if (!in_array($pep, ['NO', 'NACIONAL', 'EXTRANJERA', 'NO_INFORMA'], true)) {
            throw new ApiException(
                'Seleccioná una condición PEP válida.',
                'INVALID_PEP_STATUS',
                422,
                ['pep_estado' => 'Valor no permitido.']
            );
        }
        $terrorism = strtoupper(trim((string)($payload['terrorismo_resultado'] ?? 'PENDIENTE')));
        if (!in_array(
            $terrorism,
            ['PENDIENTE', 'SIN_COINCIDENCIA', 'COINCIDENCIA_POTENCIAL'],
            true
        )) {
            throw new ApiException(
                'Seleccioná un resultado válido del control RePET/listas.',
                'INVALID_TERRORISM_RESULT',
                422,
                ['terrorismo_resultado' => 'Valor no permitido.']
            );
        }

        return [
            'actividad' => $this->requiredText($payload['actividad'] ?? null, 'actividad', 3, 240),
            'proposito' => $this->requiredText($payload['proposito'] ?? null, 'proposito', 3, 500),
            'origen_fondos' => $this->requiredText(
                $payload['origen_fondos'] ?? null,
                'origen_fondos',
                3,
                500
            ),
            'monto_solicitado' => $this->nonNegativeMoney(
                $payload['monto_solicitado'] ?? null,
                'monto_solicitado',
                true
            ),
            'ingresos_mensuales' => $this->nonNegativeMoney(
                $payload['ingresos_mensuales'] ?? 0,
                'ingresos_mensuales'
            ),
            'patrimonio_estimado' => $this->nonNegativeMoney(
                $payload['patrimonio_estimado'] ?? 0,
                'patrimonio_estimado'
            ),
            'identidad_verificada' => $this->boolean($payload['identidad_verificada'] ?? false),
            'documentacion_completa' => $this->boolean($payload['documentacion_completa'] ?? false),
            'origen_fondos_documentado' => $this->boolean(
                $payload['origen_fondos_documentado'] ?? false
            ),
            'pep_estado' => $pep,
            'terrorismo_resultado' => $terrorism,
            'no_residente' => $this->boolean($payload['no_residente'] ?? false),
            'jurisdiccion_riesgo' => $this->boolean(
                $payload['jurisdiccion_riesgo'] ?? false
            ),
            'efectivo_intensivo' => $this->boolean(
                $payload['efectivo_intensivo'] ?? false
            ),
            'fondos_terceros' => $this->boolean($payload['fondos_terceros'] ?? false),
            'datos_contradictorios' => $this->boolean(
                $payload['datos_contradictorios'] ?? false
            ),
            'comportamiento_inusual' => $this->boolean(
                $payload['comportamiento_inusual'] ?? false
            ),
            'observaciones' => $this->optionalText($payload['observaciones'] ?? null, 4000),
        ];
    }

    private function documentationState(array $input, array $result): string
    {
        if (
            $input['identidad_verificada']
            && $input['documentacion_completa']
            && $input['origen_fondos_documentado']
            && $input['terrorismo_resultado'] !== 'PENDIENTE'
            && $result['documentacion_pendiente'] === []
        ) {
            return 'COMPLETA';
        }
        if (
            $input['identidad_verificada']
            || $input['documentacion_completa']
            || $input['origen_fondos_documentado']
        ) {
            return 'PARCIAL';
        }
        return 'PENDIENTE';
    }

    private function requireReport(int $reportId): array
    {
        $report = $this->repository->report($reportId);
        if (!$report) {
            throw new ApiException(
                'El informe solicitado no existe.',
                'RISK_REPORT_NOT_FOUND',
                404
            );
        }
        return $report;
    }

    private function requireMutableReport(int $reportId): array
    {
        $report = $this->requireReport($reportId);
        if ((string)$report['estado'] === 'ANULADO') {
            throw new ApiException(
                'El informe está anulado y no admite modificaciones.',
                'RISK_REPORT_ANNULLED',
                409
            );
        }
        return $report;
    }

    private function bcraDenomination(array $sources): ?string
    {
        foreach ($sources as $source) {
            if ($source['fuente'] !== BcraCentralDeudoresClient::SOURCE_CURRENT) {
                continue;
            }
            $denomination = trim((string)(
                $source['normalizado']['resumen']['denominacion'] ?? ''
            ));
            return $denomination !== '' ? $denomination : null;
        }
        return null;
    }

    private function reportHash(int $reportId, array $summary, array $sources): string
    {
        return hash('sha256', $this->encode([
            'id_informe' => $reportId,
            'resumen' => $summary,
            'fuentes' => array_map(
                static fn (array $source): array => [
                    'fuente' => $source['fuente'],
                    'estado' => $source['estado'],
                    'hash' => $source['hash_sha256'] ?? null,
                    'consultado_en' => $source['consultado_en'],
                ],
                $sources
            ),
        ]));
    }

    private function hasPermission(array $session, string $permission): bool
    {
        $permissions = is_array($session['permisos'] ?? null)
            ? $session['permisos']
            : [];
        return in_array('*', $permissions, true)
            || in_array($permission, $permissions, true);
    }

    private function userId(array $session): int
    {
        $userId = (int)($session['id_usuario'] ?? 0);
        if ($userId < 1) {
            throw new ApiException(
                'No se pudo identificar al usuario de la operación.',
                'INVALID_SESSION_ACTOR',
                401
            );
        }
        return $userId;
    }

    private function requiredText(
        mixed $value,
        string $field,
        int $minimum,
        int $maximum
    ): string {
        $text = trim((string)$value);
        if (mb_strlen($text) < $minimum || mb_strlen($text) > $maximum) {
            throw new ApiException(
                'Revisá los campos obligatorios de la evaluación.',
                'INVALID_EVALUATION_DATA',
                422,
                [$field => "Debe contener entre {$minimum} y {$maximum} caracteres."]
            );
        }
        return $text;
    }

    private function optionalText(mixed $value, int $maximum): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maximum) {
            throw new ApiException(
                'Uno de los textos supera la longitud permitida.',
                'TEXT_TOO_LONG',
                422
            );
        }
        return $text;
    }

    private function nonNegativeMoney(
        mixed $value,
        string $field,
        bool $positive = false
    ): float {
        if ($value === null || $value === '' || !is_numeric($value)) {
            throw new ApiException(
                'Revisá los importes de la evaluación.',
                'INVALID_MONEY',
                422,
                [$field => 'Ingresá un importe válido.']
            );
        }
        $number = round((float)$value, 2);
        if ($number < 0 || ($positive && $number <= 0) || $number > 999999999999999.99) {
            throw new ApiException(
                'Revisá los importes de la evaluación.',
                'INVALID_MONEY',
                422,
                [$field => $positive ? 'Debe ser mayor que cero.' : 'No puede ser negativo.']
            );
        }
        return $number;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array($value, [1, '1', 'true', 'TRUE', 'si', 'SI'], true);
    }

    private function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
        );
    }

    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
