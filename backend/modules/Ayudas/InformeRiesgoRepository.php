<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Core\Connection;
use App\Core\IdentityConnection;
use PDO;

final class InformeRiesgoRepository
{
    private function db(): PDO
    {
        return Connection::get();
    }

    public function personById(int $personId): ?array
    {
        return $this->one($this->personSql() . ' WHERE p.id_persona = :valor LIMIT 1', [
            'valor' => $personId,
        ]);
    }

    public function personByCuit(string $cuit): ?array
    {
        return $this->one($this->personSql() . ' WHERE p.cuit_cuil = :valor LIMIT 1', [
            'valor' => $cuit,
        ]);
    }

    public function internalBackground(?int $personId, string $cuit): array
    {
        $person = $personId !== null
            ? $this->personById($personId)
            : $this->personByCuit($cuit);
        if (!$person) {
            return [
                'persona_encontrada' => false,
                'persona' => null,
                'ayudas' => [
                    'cantidad_total' => 0,
                    'cantidad_vigentes' => 0,
                    'cantidad_finalizadas' => 0,
                    'cantidad_anuladas' => 0,
                    'capital_historico_ars' => 0.0,
                    'saldo_pendiente_estimado' => 0.0,
                    'cuotas_vencidas' => 0,
                ],
                'documentos' => [
                    'cantidad_total' => 0,
                    'vigentes' => 0,
                    'vencidos' => 0,
                    'pendientes' => 0,
                ],
                'vinculos' => [],
                'vinculos_pep' => 0,
            ];
        }

        $resolvedPersonId = (int)$person['id_persona'];
        $aids = $this->one(
            'SELECT
                COUNT(*) AS cantidad_total,
                SUM(a.estado = \'VIGENTE\') AS cantidad_vigentes,
                SUM(a.estado = \'FINALIZADA\') AS cantidad_finalizadas,
                SUM(a.estado = \'RENOVADA\') AS cantidad_renovadas,
                SUM(a.estado = \'ANULADA\') AS cantidad_anuladas,
                COALESCE(SUM(a.capital_equivalente_ars), 0) AS capital_historico_ars,
                COALESCE(SUM(
                    CASE WHEN a.estado = \'VIGENTE\'
                         THEN cuotas.saldo_pendiente ELSE 0 END
                ), 0) AS saldo_pendiente_estimado,
                COALESCE(SUM(cuotas.cuotas_vencidas), 0) AS cuotas_vencidas
             FROM ae_ayudas a
             LEFT JOIN (
                 SELECT
                     id_ayuda,
                     SUM(CASE WHEN estado = \'PENDIENTE\' THEN importe_cuota ELSE 0 END)
                         AS saldo_pendiente,
                     SUM(estado = \'PENDIENTE\' AND fecha_vencimiento < CURDATE())
                         AS cuotas_vencidas
                 FROM ae_cuotas
                 GROUP BY id_ayuda
             ) cuotas ON cuotas.id_ayuda = a.id_ayuda
             WHERE a.id_persona = :id',
            ['id' => $resolvedPersonId]
        ) ?: [];

        $documents = $this->one(
            'SELECT
                COUNT(*) AS cantidad_total,
                SUM(estado = \'VIGENTE\') AS vigentes,
                SUM(estado = \'VENCIDO\'
                    OR (fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE())) AS vencidos,
                SUM(estado = \'PENDIENTE\') AS pendientes
             FROM per_documentos
             WHERE id_persona = :id',
            ['id' => $resolvedPersonId]
        ) ?: [];

        $links = $this->rows(
            'SELECT
                v.tipo_vinculo,
                v.porcentaje_participacion,
                v.alcance,
                vinculada.id_persona,
                vinculada.nombre_exhibicion AS nombre,
                vinculada.cuit_cuil,
                vinculada.es_pep,
                vinculada.activo
             FROM per_vinculos v
             INNER JOIN per_personas vinculada
                ON vinculada.id_persona = v.id_persona_vinculada
             WHERE v.id_persona_titular = :id
               AND v.activo = 1
             ORDER BY v.tipo_vinculo, vinculada.nombre_exhibicion',
            ['id' => $resolvedPersonId]
        );

        return [
            'persona_encontrada' => true,
            'persona' => $person,
            'ayudas' => [
                'cantidad_total' => (int)($aids['cantidad_total'] ?? 0),
                'cantidad_vigentes' => (int)($aids['cantidad_vigentes'] ?? 0),
                'cantidad_finalizadas' => (int)($aids['cantidad_finalizadas'] ?? 0),
                'cantidad_renovadas' => (int)($aids['cantidad_renovadas'] ?? 0),
                'cantidad_anuladas' => (int)($aids['cantidad_anuladas'] ?? 0),
                'capital_historico_ars' => (float)($aids['capital_historico_ars'] ?? 0),
                'saldo_pendiente_estimado' => (float)($aids['saldo_pendiente_estimado'] ?? 0),
                'cuotas_vencidas' => (int)($aids['cuotas_vencidas'] ?? 0),
            ],
            'documentos' => [
                'cantidad_total' => (int)($documents['cantidad_total'] ?? 0),
                'vigentes' => (int)($documents['vigentes'] ?? 0),
                'vencidos' => (int)($documents['vencidos'] ?? 0),
                'pendientes' => (int)($documents['pendientes'] ?? 0),
            ],
            'vinculos' => array_map(static fn (array $link): array => [
                'tipo' => (string)$link['tipo_vinculo'],
                'id_persona' => (int)$link['id_persona'],
                'nombre' => (string)$link['nombre'],
                'cuit_cuil_enmascarado' => !empty($link['cuit_cuil'])
                    ? CuitValidator::mask((string)$link['cuit_cuil'])
                    : null,
                'es_pep' => (bool)$link['es_pep'],
                'activo' => (bool)$link['activo'],
                'porcentaje_participacion' => $link['porcentaje_participacion'] !== null
                    ? (float)$link['porcentaje_participacion']
                    : null,
                'alcance' => $link['alcance'],
            ], $links),
            'vinculos_pep' => count(array_filter(
                $links,
                static fn (array $link): bool => (bool)$link['es_pep']
            )),
        ];
    }

    public function createReport(array $data): int
    {
        $statement = $this->db()->prepare(
            'INSERT INTO aem_informes_riesgo
             (id_persona,cuit_cuil,denominacion,estado,riesgo_crediticio,
              riesgo_uif,documentacion_estado,antecedentes_json,resumen_json,
              correlation_id,creado_por)
             VALUES
             (:id_persona,:cuit,:denominacion,\'GENERANDO\',\'NO_DETERMINADO\',
              \'NO_DETERMINADO\',\'PENDIENTE\',:antecedentes,:resumen,
              :correlation_id,:creado_por)'
        );
        $statement->execute([
            'id_persona' => $data['id_persona'],
            'cuit' => $data['cuit'],
            'denominacion' => $data['denominacion'],
            'antecedentes' => $this->encode($data['antecedentes']),
            'resumen' => $this->encode($data['resumen'] ?? []),
            'correlation_id' => $data['correlation_id'],
            'creado_por' => $data['creado_por'],
        ]);
        return (int)$this->db()->lastInsertId();
    }

    public function updateReport(
        int $reportId,
        string $state,
        string $creditRisk,
        ?string $uifRisk,
        ?string $documentationState,
        array $summary,
        string $integrityHash,
        int $userId,
        ?string $denomination = null
    ): void {
        $this->db()->prepare(
            'UPDATE aem_informes_riesgo
             SET estado = :estado,
                 riesgo_crediticio = :riesgo_crediticio,
                 riesgo_uif = COALESCE(:riesgo_uif, riesgo_uif),
                 documentacion_estado = COALESCE(:documentacion, documentacion_estado),
                 resumen_json = :resumen,
                 hash_integridad = :hash,
                 denominacion = COALESCE(NULLIF(:denominacion, \'\'), denominacion),
                 actualizado_por = :usuario
             WHERE id_informe = :id'
        )->execute([
            'estado' => $state,
            'riesgo_crediticio' => $creditRisk,
            'riesgo_uif' => $uifRisk,
            'documentacion' => $documentationState,
            'resumen' => $this->encode($summary),
            'hash' => $integrityHash,
            'denominacion' => $denomination,
            'usuario' => $userId,
            'id' => $reportId,
        ]);
    }

    public function updateEvaluationResult(
        int $reportId,
        string $uifRisk,
        string $documentationState,
        string $state,
        int $userId
    ): void {
        $this->db()->prepare(
            'UPDATE aem_informes_riesgo
             SET riesgo_uif = :riesgo,
                 documentacion_estado = :documentacion,
                 estado = :estado,
                 actualizado_por = :usuario
             WHERE id_informe = :id'
        )->execute([
            'riesgo' => $uifRisk,
            'documentacion' => $documentationState,
            'estado' => $state,
            'usuario' => $userId,
            'id' => $reportId,
        ]);
    }

    public function insertSource(int $reportId, array $source, bool $fromCache = false): int
    {
        $statement = $this->db()->prepare(
            'INSERT INTO aem_informes_fuentes
             (id_informe,fuente,estado,es_cache,consultado_en,periodo,http_status,
              respuesta_json,normalizado_json,hash_sha256,error_codigo,error_mensaje,
              duracion_ms)
             VALUES
             (:id_informe,:fuente,:estado,:es_cache,:consultado_en,:periodo,:http_status,
              :respuesta,:normalizado,:hash,:error_codigo,:error_mensaje,:duracion_ms)'
        );
        $statement->execute([
            'id_informe' => $reportId,
            'fuente' => $source['fuente'],
            'estado' => $source['estado'],
            'es_cache' => $fromCache ? 1 : 0,
            'consultado_en' => $source['consultado_en'],
            'periodo' => $source['periodo'] ?? null,
            'http_status' => $source['http_status'] ?? null,
            'respuesta' => $this->encode($source['respuesta'] ?? []),
            'normalizado' => $this->encode($source['normalizado'] ?? []),
            'hash' => $source['hash_sha256'] ?? null,
            'error_codigo' => $source['error_codigo'] ?? null,
            'error_mensaje' => $source['error_mensaje'] ?? null,
            'duracion_ms' => $source['duracion_ms'] ?? null,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    public function cachedSource(string $cuit, string $source, string $since): ?array
    {
        // Primero se resuelve únicamente la PK. Las respuestas BCRA pueden ser
        // muy grandes y MySQL incluye las columnas JSON en el buffer si se hace
        // ORDER BY sobre SELECT f.*, lo que provoca el error 1038 en servidores
        // con sort_buffer_size acotado.
        $latest = $this->one(
            'SELECT f.id_fuente
             FROM aem_informes_fuentes f
             INNER JOIN aem_informes_riesgo i ON i.id_informe = f.id_informe
             WHERE i.cuit_cuil = :cuit
               AND f.fuente = :fuente
               AND f.estado IN (\'OK\', \'SIN_DATOS\')
               AND f.consultado_en >= :desde
             ORDER BY f.consultado_en DESC, f.id_fuente DESC
             LIMIT 1',
            ['cuit' => $cuit, 'fuente' => $source, 'desde' => $since]
        );
        if (!$latest) {
            return null;
        }

        $row = $this->one(
            'SELECT
                id_fuente,fuente,estado,consultado_en,periodo,http_status,
                respuesta_json,normalizado_json,hash_sha256,error_codigo,
                error_mensaje,duracion_ms
             FROM aem_informes_fuentes
             WHERE id_fuente = :id
             LIMIT 1',
            ['id' => (int)$latest['id_fuente']]
        );
        if (!$row) {
            return null;
        }
        return [
            'fuente' => (string)$row['fuente'],
            'estado' => (string)$row['estado'],
            'consultado_en' => (string)$row['consultado_en'],
            'periodo' => $row['periodo'],
            'http_status' => $row['http_status'] !== null ? (int)$row['http_status'] : null,
            'respuesta' => $this->decode($row['respuesta_json']),
            'normalizado' => $this->decode($row['normalizado_json']),
            'hash_sha256' => $row['hash_sha256'],
            'error_codigo' => $row['error_codigo'],
            'error_mensaje' => $row['error_mensaje'],
            'duracion_ms' => $row['duracion_ms'] !== null ? (int)$row['duracion_ms'] : null,
        ];
    }

    public function rules(): array
    {
        $rows = $this->rows(
            'SELECT codigo,nombre,severidad,peso,accion_requerida,
                    configuracion_json,version_reglas,activa
             FROM aem_reglas_uif
             ORDER BY codigo'
        );
        $rules = [];
        foreach ($rows as $row) {
            $rules[(string)$row['codigo']] = [
                'codigo' => (string)$row['codigo'],
                'nombre' => (string)$row['nombre'],
                'severidad' => (string)$row['severidad'],
                'peso' => (int)$row['peso'],
                'accion_requerida' => (string)$row['accion_requerida'],
                'configuracion' => $this->decode($row['configuracion_json']),
                'version_reglas' => (string)$row['version_reglas'],
                'activa' => (bool)$row['activa'],
            ];
        }
        return $rules;
    }

    public function nextEvaluationVersion(int $reportId): int
    {
        $statement = $this->db()->prepare(
            'SELECT COALESCE(MAX(version_numero), 0) + 1
             FROM aem_evaluaciones_uif
             WHERE id_informe = :id
             FOR UPDATE'
        );
        $statement->execute(['id' => $reportId]);
        return (int)$statement->fetchColumn();
    }

    public function insertEvaluation(
        int $reportId,
        int $version,
        array $input,
        array $result,
        int $userId
    ): int {
        $statement = $this->db()->prepare(
            'INSERT INTO aem_evaluaciones_uif
             (id_informe,version_numero,version_reglas,nivel_riesgo,medida_requerida,
              actividad,proposito,origen_fondos,monto_solicitado,ingresos_mensuales,
              patrimonio_estimado,identidad_verificada,documentacion_completa,
              origen_fondos_documentado,pep_estado,terrorismo_resultado,no_residente,
              jurisdiccion_riesgo,efectivo_intensivo,fondos_terceros,
              datos_contradictorios,comportamiento_inusual,observaciones,
              factores_json,documentacion_pendiente_json,creado_por)
             VALUES
             (:id_informe,:version_numero,:version_reglas,:nivel_riesgo,:medida,
              :actividad,:proposito,:origen_fondos,:monto,:ingresos,:patrimonio,
              :identidad,:documentacion,:origen_documentado,:pep,:terrorismo,
              :no_residente,:jurisdiccion,:efectivo,:terceros,:contradictorios,
              :inusual,:observaciones,:factores,:pendientes,:usuario)'
        );
        $statement->execute([
            'id_informe' => $reportId,
            'version_numero' => $version,
            'version_reglas' => $result['version_reglas'],
            'nivel_riesgo' => $result['nivel_riesgo'],
            'medida' => $result['medida_requerida'],
            'actividad' => $input['actividad'],
            'proposito' => $input['proposito'],
            'origen_fondos' => $input['origen_fondos'],
            'monto' => $input['monto_solicitado'],
            'ingresos' => $input['ingresos_mensuales'],
            'patrimonio' => $input['patrimonio_estimado'],
            'identidad' => $input['identidad_verificada'] ? 1 : 0,
            'documentacion' => $input['documentacion_completa'] ? 1 : 0,
            'origen_documentado' => $input['origen_fondos_documentado'] ? 1 : 0,
            'pep' => $input['pep_estado'],
            'terrorismo' => $input['terrorismo_resultado'],
            'no_residente' => $input['no_residente'] ? 1 : 0,
            'jurisdiccion' => $input['jurisdiccion_riesgo'] ? 1 : 0,
            'efectivo' => $input['efectivo_intensivo'] ? 1 : 0,
            'terceros' => $input['fondos_terceros'] ? 1 : 0,
            'contradictorios' => $input['datos_contradictorios'] ? 1 : 0,
            'inusual' => $input['comportamiento_inusual'] ? 1 : 0,
            'observaciones' => $input['observaciones'],
            'factores' => $this->encode($result['factores']),
            'pendientes' => $this->encode($result['documentacion_pendiente']),
            'usuario' => $userId,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    public function insertAlerts(
        int $reportId,
        int $evaluationId,
        array $alerts
    ): void {
        $statement = $this->db()->prepare(
            'INSERT INTO aem_alertas_riesgo
             (id_informe,id_evaluacion,tipo,codigo,severidad,descripcion,
              accion_requerida,evidencia_json,estado)
             VALUES
             (:id_informe,:id_evaluacion,\'UIF\',:codigo,:severidad,:descripcion,
              :accion,:evidencia,:estado)'
        );
        foreach ($alerts as $alert) {
            $statement->execute([
                'id_informe' => $reportId,
                'id_evaluacion' => $evaluationId,
                'codigo' => $alert['codigo'],
                'severidad' => $alert['severidad'],
                'descripcion' => $alert['descripcion'],
                'accion' => $alert['accion_requerida'],
                'evidencia' => $this->encode($alert['evidencia'] ?? []),
                'estado' => $alert['severidad'] === 'CRITICA' ? 'ESCALADA' : 'ABIERTA',
            ]);
        }
    }

    public function insertDictamen(
        int $reportId,
        string $result,
        ?string $conditions,
        ?string $basis,
        bool $complianceReview,
        int $userId
    ): int {
        $statement = $this->db()->prepare(
            'INSERT INTO aem_dictamenes
             (id_informe,resultado,condiciones,fundamento,es_revision_cumplimiento,
              dictaminado_por)
             VALUES (:id,:resultado,:condiciones,:fundamento,:cumplimiento,:usuario)'
        );
        $statement->execute([
            'id' => $reportId,
            'resultado' => $result,
            'condiciones' => $conditions,
            'fundamento' => $basis,
            'cumplimiento' => $complianceReview ? 1 : 0,
            'usuario' => $userId,
        ]);
        $decisionId = (int)$this->db()->lastInsertId();
        $this->db()->prepare(
            'UPDATE aem_informes_riesgo
             SET estado = \'DICTAMINADO\', actualizado_por = :usuario
             WHERE id_informe = :id'
        )->execute(['usuario' => $userId, 'id' => $reportId]);
        return $decisionId;
    }

    public function insertDocument(array $data): int
    {
        $statement = $this->db()->prepare(
            'INSERT INTO aem_informes_documentos
             (id_informe,id_fuente,tipo,nombre_original,ruta_privada,mime,
              tamano_bytes,hash_sha256,subido_por)
             VALUES
             (:id_informe,:id_fuente,:tipo,:nombre,:ruta,:mime,:tamano,:hash,:usuario)'
        );
        $statement->execute([
            'id_informe' => $data['id_informe'],
            'id_fuente' => $data['id_fuente'],
            'tipo' => $data['tipo'],
            'nombre' => $data['nombre_original'],
            'ruta' => $data['ruta_privada'],
            'mime' => $data['mime'],
            'tamano' => $data['tamano_bytes'],
            'hash' => $data['hash_sha256'],
            'usuario' => $data['subido_por'],
        ]);
        return (int)$this->db()->lastInsertId();
    }

    public function report(int $reportId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM aem_informes_riesgo WHERE id_informe = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        return $this->one($sql, ['id' => $reportId]);
    }

    public function latestEvaluation(int $reportId): ?array
    {
        $row = $this->one(
            'SELECT *
             FROM aem_evaluaciones_uif
             WHERE id_informe = :id
             ORDER BY version_numero DESC, id_evaluacion DESC
             LIMIT 1',
            ['id' => $reportId]
        );
        return $this->normalizeEvaluation($row);
    }

    public function latestSources(int $reportId): array
    {
        // La selección y el orden se hacen con filas livianas. Luego se leen
        // los JSON por PK y se ordenan en PHP. Así MySQL nunca intenta ordenar
        // el payload completo de empresas con historiales BCRA voluminosos.
        $latestIds = $this->rows(
            'SELECT fuente, MAX(id_fuente) AS id_fuente
             FROM aem_informes_fuentes
             WHERE id_informe = :id
             GROUP BY fuente',
            ['id' => $reportId]
        );

        $rowsBySource = [];
        foreach ($latestIds as $latest) {
            $row = $this->one(
                'SELECT
                    id_fuente,fuente,estado,es_cache,consultado_en,periodo,
                    http_status,normalizado_json,hash_sha256,error_codigo,
                    error_mensaje,duracion_ms
                 FROM aem_informes_fuentes
                 WHERE id_fuente = :id
                 LIMIT 1',
                ['id' => (int)$latest['id_fuente']]
            );
            if ($row) {
                $rowsBySource[(string)$row['fuente']] = $row;
            }
        }

        $rows = [];
        foreach ([
            'BCRA_DEUDA_ACTUAL',
            'BCRA_HISTORICO',
            'BCRA_CHEQUES_RECHAZADOS',
            'REPET',
        ] as $source) {
            if (isset($rowsBySource[$source])) {
                $rows[] = $rowsBySource[$source];
            }
        }
        return array_map(fn (array $row): array => $this->publicSource($row), $rows);
    }

    public function hasCriticalAlert(int $reportId): bool
    {
        $evaluation = $this->latestEvaluation($reportId);
        if (!$evaluation) {
            return false;
        }
        $statement = $this->db()->prepare(
            'SELECT 1
             FROM aem_alertas_riesgo
             WHERE id_evaluacion = :evaluacion
               AND severidad = \'CRITICA\'
               AND estado IN (\'ABIERTA\', \'EN_ANALISIS\', \'ESCALADA\')
             LIMIT 1'
        );
        $statement->execute(['evaluacion' => $evaluation['id_evaluacion']]);
        return (bool)$statement->fetchColumn();
    }

    public function list(array $filters): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['cuit'])) {
            $where[] = 'i.cuit_cuil = :cuit';
            $params['cuit'] = $filters['cuit'];
        }
        if (!empty($filters['id_persona'])) {
            $where[] = 'i.id_persona = :persona';
            $params['persona'] = (int)$filters['id_persona'];
        }
        $limit = max(1, min(100, (int)($filters['limite'] ?? 30)));
        $rows = $this->rows(
            'SELECT
                i.id_informe,i.id_persona,i.cuit_cuil,i.denominacion,i.estado,
                i.riesgo_crediticio,i.riesgo_uif,i.documentacion_estado,
                i.hash_integridad,i.creado_por,i.creado_en,i.actualizado_en,
                (SELECT d.resultado
                 FROM aem_dictamenes d
                 WHERE d.id_informe = i.id_informe
                 ORDER BY d.id_dictamen DESC LIMIT 1) AS dictamen_resultado
             FROM aem_informes_riesgo i'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY i.id_informe DESC LIMIT ' . $limit,
            $params
        );
        foreach ($rows as &$row) {
            $row['id_informe'] = (int)$row['id_informe'];
            $row['id_persona'] = $row['id_persona'] !== null
                ? (int)$row['id_persona']
                : null;
            $row['cuit_cuil_enmascarado'] = CuitValidator::mask((string)$row['cuit_cuil']);
            unset($row['cuit_cuil']);
            $row['creado_por_nombre'] = $this->userName((int)$row['creado_por']);
        }
        unset($row);
        return $rows;
    }

    public function detail(int $reportId): ?array
    {
        $report = $this->report($reportId);
        if (!$report) {
            return null;
        }
        $report['id_informe'] = (int)$report['id_informe'];
        $report['id_persona'] = $report['id_persona'] !== null
            ? (int)$report['id_persona']
            : null;
        $report['cuit_cuil_enmascarado'] = CuitValidator::mask((string)$report['cuit_cuil']);
        $report['antecedentes'] = $this->decode($report['antecedentes_json']);
        $report['resumen'] = $this->decode($report['resumen_json']);
        $report['creado_por_nombre'] = $this->userName((int)$report['creado_por']);
        unset($report['antecedentes_json'], $report['resumen_json']);

        $evaluation = $this->latestEvaluation($reportId);
        $alerts = [];
        if ($evaluation) {
            $alerts = $this->rows(
                'SELECT id_alerta,tipo,codigo,severidad,descripcion,accion_requerida,
                        evidencia_json,estado,resuelta_por,resuelta_en,creado_en
                 FROM aem_alertas_riesgo
                 WHERE id_evaluacion = :id
                 ORDER BY FIELD(severidad, \'CRITICA\',\'ALTA\',\'MEDIA\',\'BAJA\'),
                          id_alerta',
                ['id' => $evaluation['id_evaluacion']]
            );
            foreach ($alerts as &$alert) {
                $alert['id_alerta'] = (int)$alert['id_alerta'];
                $alert['evidencia'] = $this->decode($alert['evidencia_json']);
                unset($alert['evidencia_json']);
            }
            unset($alert);
        }

        $dictamens = $this->rows(
            'SELECT id_dictamen,resultado,condiciones,fundamento,
                    es_revision_cumplimiento,dictaminado_por,dictaminado_en
             FROM aem_dictamenes
             WHERE id_informe = :id
             ORDER BY id_dictamen DESC',
            ['id' => $reportId]
        );
        foreach ($dictamens as &$dictamen) {
            $dictamen['id_dictamen'] = (int)$dictamen['id_dictamen'];
            $dictamen['es_revision_cumplimiento'] = (bool)$dictamen['es_revision_cumplimiento'];
            $dictamen['dictaminado_por_nombre'] = $this->userName(
                (int)$dictamen['dictaminado_por']
            );
        }
        unset($dictamen);

        $documents = $this->rows(
            'SELECT id_documento,id_fuente,tipo,nombre_original,mime,tamano_bytes,
                    hash_sha256,subido_por,subido_en
             FROM aem_informes_documentos
             WHERE id_informe = :id
             ORDER BY id_documento DESC',
            ['id' => $reportId]
        );
        foreach ($documents as &$document) {
            $document['id_documento'] = (int)$document['id_documento'];
            $document['id_fuente'] = $document['id_fuente'] !== null
                ? (int)$document['id_fuente']
                : null;
            $document['tamano_bytes'] = (int)$document['tamano_bytes'];
            $document['subido_por_nombre'] = $this->userName((int)$document['subido_por']);
        }
        unset($document);

        return [
            'informe' => $report,
            'fuentes' => $this->latestSources($reportId),
            'evaluacion_uif' => $evaluation,
            'alertas' => $alerts,
            'dictamen' => $dictamens[0] ?? null,
            'historial_dictamenes' => $dictamens,
            'documentos' => $documents,
            'fuentes_oficiales' => [
                'bcra' => 'https://www.bcra.gob.ar/apis-banco-central/',
                'uif_normativa' => 'https://www.argentina.gob.ar/normativa/nacional/385327/actualizacion',
                'repet' => 'https://repet.jus.gob.ar/',
                'pep' => 'https://www.argentina.gob.ar/uif/personas-expuestas-politicamente',
                'renaper_sid' => 'https://www.argentina.gob.ar/sid/modalidades-y-productos',
                'renaper_adhesion' => 'https://www.argentina.gob.ar/sid/adherir',
            ],
            'integraciones' => [
                'bcra' => [
                    'modo' => 'AUTOMATICO_PUBLICO',
                    'estado' => 'INTEGRADO',
                ],
                'repet' => [
                    'modo' => 'AUTOMATICO_PUBLICO',
                    'estado' => 'INTEGRADO',
                ],
                'renaper' => [
                    'modo' => 'API_RESTRINGIDA',
                    'estado' => 'PENDIENTE_HABILITACION_MUTUAL',
                ],
            ],
            'advertencia_regulatoria' => 'El riesgo UIF es una evaluación interna preliminar. RePET genera coincidencias potenciales sujetas a revisión humana. La UIF no aprobó ni rechazó esta ayuda y no se generó un ROS automático.',
        ];
    }

    public function document(int $documentId): ?array
    {
        return $this->one(
            'SELECT * FROM aem_informes_documentos WHERE id_documento = :id LIMIT 1',
            ['id' => $documentId]
        );
    }

    private function normalizeEvaluation(?array $evaluation): ?array
    {
        if (!$evaluation) {
            return null;
        }
        foreach ([
            'id_evaluacion',
            'id_informe',
            'version_numero',
        ] as $field) {
            $evaluation[$field] = (int)$evaluation[$field];
        }
        foreach ([
            'monto_solicitado',
            'ingresos_mensuales',
            'patrimonio_estimado',
        ] as $field) {
            $evaluation[$field] = $evaluation[$field] !== null
                ? (float)$evaluation[$field]
                : null;
        }
        foreach ([
            'identidad_verificada',
            'documentacion_completa',
            'origen_fondos_documentado',
            'no_residente',
            'jurisdiccion_riesgo',
            'efectivo_intensivo',
            'fondos_terceros',
            'datos_contradictorios',
            'comportamiento_inusual',
        ] as $field) {
            $evaluation[$field] = (bool)$evaluation[$field];
        }
        $evaluation['factores'] = $this->decode($evaluation['factores_json']);
        $evaluation['documentacion_pendiente'] = $this->decode(
            $evaluation['documentacion_pendiente_json']
        );
        $evaluation['creado_por_nombre'] = $this->userName((int)$evaluation['creado_por']);
        unset($evaluation['factores_json'], $evaluation['documentacion_pendiente_json']);
        return $evaluation;
    }

    private function publicSource(array $row): array
    {
        return [
            'id_fuente' => (int)$row['id_fuente'],
            'fuente' => (string)$row['fuente'],
            'estado' => (string)$row['estado'],
            'es_cache' => (bool)$row['es_cache'],
            'consultado_en' => (string)$row['consultado_en'],
            'periodo' => $row['periodo'],
            'http_status' => $row['http_status'] !== null ? (int)$row['http_status'] : null,
            'normalizado' => $this->decode($row['normalizado_json']),
            'hash_sha256' => $row['hash_sha256'],
            'error_codigo' => $row['error_codigo'],
            'error_mensaje' => $row['error_mensaje'],
            'duracion_ms' => $row['duracion_ms'] !== null ? (int)$row['duracion_ms'] : null,
        ];
    }

    private function personSql(): string
    {
        return 'SELECT
                    p.id_persona,p.tipo_persona,p.nombre_exhibicion,p.cuit_cuil,
                    p.email,p.telefono,p.actividad,p.residente,p.es_pep,
                    p.sujeto_obligado,p.activo,p.fecha_actualizacion_arca,
                    pf.dni,pf.nombres,pf.apellidos,pf.fecha_nacimiento,
                    pj.razon_social,pj.nombre_fantasia,
                    asoc.id_asociado AS numero_socio,
                    asoc.estado AS estado_asociado,
                    COALESCE(df.ingresos_mensuales, 0) AS ingresos_mensuales,
                    COALESCE(df.patrimonio_estimado, 0) AS patrimonio_estimado,
                    pais.nombre AS pais_residencia,
                    zona.nombre AS zona_geografica
                FROM per_personas p
                LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
                LEFT JOIN per_personas_juridicas pj ON pj.id_persona = p.id_persona
                LEFT JOIN per_asociados asoc ON asoc.id_persona = p.id_persona
                LEFT JOIN per_datos_financieros df ON df.id_persona = p.id_persona
                LEFT JOIN sub_paises pais ON pais.id_pais = p.id_pais_residencia
                LEFT JOIN sub_zonas_geograficas zona
                    ON zona.id_zona_geografica = p.id_zona_geografica';
    }

    private function userName(int $userId): ?string
    {
        if ($userId < 1) {
            return null;
        }
        $statement = IdentityConnection::get()->prepare(
            'SELECT nombre FROM idn_usuarios WHERE id_usuario = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $name = $statement->fetchColumn();
        return $name === false ? null : (string)$name;
    }

    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
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

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
