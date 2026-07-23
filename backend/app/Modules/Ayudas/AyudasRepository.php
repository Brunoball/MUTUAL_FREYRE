<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Core\Connection;
use App\Core\IdentityConnection;
use PDO;
use PDOException;

final class AyudasRepository
{
    private function db(): PDO
    {
        return Connection::get();
    }

    public function list(array $filters): array
    {
        $where = [];
        $params = [];

        $search = trim((string)($filters['buscar'] ?? ''));
        if ($search !== '') {
            $where[] = '(CAST(a.numero_ayuda AS CHAR) LIKE :buscar
                OR CAST(a.numero_solicitud AS CHAR) LIKE :buscar
                OR CAST(asoc.id_asociado AS CHAR) LIKE :buscar
                OR p.nombre_exhibicion LIKE :buscar
                OR COALESCE(pf.dni, p.cuit_cuil, \'\') LIKE :buscar)';
            $params['buscar'] = '%' . $search . '%';
        }

        $type = strtoupper(trim((string)($filters['tipo'] ?? '')));
        if ($type !== '') {
            $where[] = 'prod.codigo = :tipo';
            $params['tipo'] = $type;
        }

        $status = strtoupper(trim((string)($filters['estado'] ?? 'VIGENTES')));
        if ($status === 'VIGENTES') {
            $where[] = "a.estado = 'VIGENTE'";
        } elseif ($status === 'CERRADAS') {
            $where[] = "a.estado IN ('FINALIZADA','RENOVADA','ANULADA')";
        } elseif (in_array($status, ['VIGENTE', 'FINALIZADA', 'RENOVADA', 'ANULADA'], true)) {
            $where[] = 'a.estado = :estado';
            $params['estado'] = $status;
        }

        $limit = max(1, min(500, (int)($filters['limite'] ?? 250)));
        $sql = 'SELECT
                    a.id_ayuda,
                    a.numero_ayuda,
                    a.numero_solicitud,
                    a.tipo_operacion,
                    a.fecha_solicitud,
                    a.fecha_liquidacion,
                    a.fecha_vencimiento,
                    a.moneda,
                    a.capital_original,
                    a.capital_equivalente_ars,
                    a.total_a_devolver,
                    a.importe_acreditado_ars,
                    a.cantidad_cuotas,
                    a.periodicidad,
                    a.rubro,
                    a.destino,
                    a.estado,
                    a.tna,
                    a.tem,
                    a.tea,
                    a.cft,
                    prod.codigo AS tipo,
                    prod.nombre AS producto_nombre,
                    prod.sistema_amortizacion,
                    p.id_persona,
                    p.nombre_exhibicion AS socio_nombre,
                    COALESCE(pf.dni, p.cuit_cuil) AS documento,
                    asoc.id_asociado AS numero_socio,
                    COALESCE(cuotas.cuotas_pendientes, 0) AS cuotas_pendientes,
                    cuotas.proximo_vencimiento,
                    COALESCE(cuotas.saldo_pendiente, 0) AS saldo_pendiente,
                    CASE
                        WHEN a.estado = \'VIGENTE\' AND a.fecha_vencimiento < CURDATE() THEN 1
                        ELSE 0
                    END AS vencida
                FROM ae_ayudas a
                INNER JOIN ae_productos prod ON prod.id_producto = a.id_producto
                INNER JOIN per_personas p ON p.id_persona = a.id_persona
                LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
                INNER JOIN per_asociados asoc ON asoc.id_asociado = a.id_asociado
                LEFT JOIN (
                    SELECT id_ayuda,
                           SUM(estado = \'PENDIENTE\') AS cuotas_pendientes,
                           MIN(CASE WHEN estado = \'PENDIENTE\' THEN fecha_vencimiento END) AS proximo_vencimiento,
                           SUM(CASE WHEN estado = \'PENDIENTE\' THEN importe_cuota ELSE 0 END) AS saldo_pendiente
                    FROM ae_cuotas
                    GROUP BY id_ayuda
                ) cuotas ON cuotas.id_ayuda = a.id_ayuda
                ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
                ORDER BY a.fecha_liquidacion DESC, a.id_ayuda DESC
                LIMIT ' . $limit;

        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function catalogs(string $date): array
    {
        $products = $this->rows(
            'SELECT id_producto AS id, codigo, nombre, descripcion, moneda, grupo_tasa,
                    sistema_amortizacion, periodicidad_default
             FROM ae_productos
             WHERE activo = 1
             ORDER BY FIELD(codigo, \'A\',\'B\',\'E\',\'I\',\'J\')'
        );

        $associates = $this->rows(
            'SELECT asoc.id_asociado AS id,
                    asoc.id_asociado AS numero_socio,
                    p.id_persona,
                    p.nombre_exhibicion AS nombre,
                    COALESCE(pf.dni, p.cuit_cuil) AS documento,
                    asoc.estado,
                    COALESCE(df.ingresos_mensuales, 0) AS ingresos_mensuales
             FROM per_asociados asoc
             INNER JOIN per_personas p ON p.id_persona = asoc.id_persona
             LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
             LEFT JOIN per_datos_financieros df ON df.id_persona = p.id_persona
             WHERE p.activo = 1 AND asoc.estado = \'ACTIVO\'
             ORDER BY p.nombre_exhibicion
             LIMIT 5000'
        );

        $guarantors = $this->rows(
            'SELECT p.id_persona AS id,
                    p.nombre_exhibicion AS nombre,
                    COALESCE(pf.dni, p.cuit_cuil) AS documento,
                    asoc.id_asociado AS numero_socio
             FROM per_personas p
             LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
             LEFT JOIN per_asociados asoc ON asoc.id_persona = p.id_persona
             WHERE p.activo = 1
             ORDER BY p.nombre_exhibicion
             LIMIT 5000'
        );

        $rates = [];
        foreach (['A', 'B', 'EJ', 'I'] as $group) {
            $rates[$group] = $this->rateForDate($group, $date);
        }

        return [
            'productos' => $products,
            'socios' => $associates,
            'garantes' => $guarantors,
            'tasas' => $rates,
            'cotizacion_mes' => $this->quoteForDate($date),
            'rubros' => [
                ['value' => 'OTRAS NECESIDADES', 'label' => 'OTRAS NECESIDADES'],
                ['value' => 'ADQUISICIÓN DE MERCADERÍA', 'label' => 'ADQUISICIÓN DE MERCADERÍA'],
                ['value' => 'OTRO', 'label' => 'OTRO'],
            ],
            // El informe no enumera un catálogo de garantías. Son sugerencias abiertas:
            // el frontend permite escribir cualquier denominación usada por la Mutual.
            'tipos_garantia' => [
                ['value' => 'SIN GARANTIA', 'label' => 'SIN GARANTÍA'],
                ['value' => 'CON GARANTES', 'label' => 'CON GARANTES'],
            ],
        ];
    }

    public function parameters(): array
    {
        return [
            'tasas' => $this->rows(
                'SELECT id_tasa, grupo_tasa, vigencia_desde, tna, base_dias, observaciones, creado_en
                 FROM ae_tasas
                 ORDER BY vigencia_desde DESC, id_tasa DESC
                 LIMIT 100'
            ),
            'cotizaciones' => $this->rows(
                'SELECT id_cotizacion, periodo, fecha_referencia, valor_promedio, fuente,
                        observaciones, creado_en, actualizado_en
                 FROM ae_cotizaciones_dolar
                 ORDER BY periodo DESC
                 LIMIT 60'
            ),
        ];
    }

    public function productByCode(string $code): ?array
    {
        return $this->one(
            'SELECT * FROM ae_productos WHERE codigo = :codigo AND activo = 1 LIMIT 1',
            ['codigo' => $code]
        );
    }

    public function associate(int $associateId): ?array
    {
        return $this->one(
            'SELECT asoc.id_asociado, asoc.id_persona, asoc.estado,
                    p.nombre_exhibicion, p.activo,
                    COALESCE(pf.dni, p.cuit_cuil) AS documento,
                    COALESCE(df.ingresos_mensuales, 0) AS ingresos_mensuales,
                    COALESCE(df.patrimonio_estimado, 0) AS patrimonio_estimado
             FROM per_asociados asoc
             INNER JOIN per_personas p ON p.id_persona = asoc.id_persona
             LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
             LEFT JOIN per_datos_financieros df ON df.id_persona = p.id_persona
             WHERE asoc.id_asociado = :id
             LIMIT 1',
            ['id' => $associateId]
        );
    }

    public function person(int $personId): ?array
    {
        return $this->one(
            'SELECT p.id_persona, p.nombre_exhibicion, p.activo,
                    COALESCE(pf.dni, p.cuit_cuil) AS documento,
                    asoc.id_asociado AS numero_socio,
                    asoc.estado AS estado_asociado
             FROM per_personas p
             LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
             LEFT JOIN per_asociados asoc ON asoc.id_persona = p.id_persona
             WHERE p.id_persona = :id
             LIMIT 1',
            ['id' => $personId]
        );
    }

    public function rateForDate(string $group, string $date): ?array
    {
        return $this->one(
            'SELECT id_tasa, grupo_tasa, vigencia_desde, tna, base_dias, observaciones
             FROM ae_tasas
             WHERE grupo_tasa = :grupo AND vigencia_desde <= :fecha
             ORDER BY vigencia_desde DESC, id_tasa DESC
             LIMIT 1',
            ['grupo' => $group, 'fecha' => $date]
        );
    }

    public function quoteForDate(string $date): ?array
    {
        $period = substr($date, 0, 7);
        return $this->one(
            'SELECT id_cotizacion, periodo, fecha_referencia, valor_promedio, fuente, observaciones
             FROM ae_cotizaciones_dolar
             WHERE periodo = :periodo
             LIMIT 1',
            ['periodo' => $period]
        );
    }

    public function nextNumber(string $code): int
    {
        $statement = $this->db()->prepare(
            'SELECT ultimo_numero FROM ae_numeradores WHERE codigo = :codigo FOR UPDATE'
        );
        $statement->execute(['codigo' => $code]);
        $current = $statement->fetchColumn();
        if ($current === false) {
            $this->db()->prepare(
                'INSERT INTO ae_numeradores (codigo, ultimo_numero) VALUES (:codigo, 1)'
            )->execute(['codigo' => $code]);
            return 1;
        }

        $next = (int)$current + 1;
        $this->db()->prepare(
            'UPDATE ae_numeradores SET ultimo_numero = :numero WHERE codigo = :codigo'
        )->execute(['numero' => $next, 'codigo' => $code]);
        return $next;
    }

    public function insertAid(array $data): int
    {
        $columns = [
            'numero_ayuda','numero_solicitud','id_producto','id_persona','id_asociado',
            'id_ayuda_origen','tipo_operacion','fecha_solicitud','fecha_liquidacion','moneda',
            'capital_original','cotizacion_dolar','capital_equivalente_ars','plazo_cantidad',
            'plazo_unidad','cantidad_cuotas','periodicidad','fecha_primer_vencimiento',
            'fecha_vencimiento','tna','tem','tea','cft','base_dias','rubro','destino','detalle',
            'observaciones','tipo_garantia','devengamiento_total','gastos_administrativos',
            'otros_gastos','recupero_gastos','sellado','seguro','total_a_devolver',
            'importe_acreditado_ars','medio_desembolso','estado','creado_por'
        ];

        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        $statement = $this->db()->prepare(
            'INSERT INTO ae_ayudas (' . implode(',', $columns) . ')
             VALUES (' . implode(',', $placeholders) . ')'
        );
        $payload = [];
        foreach ($columns as $column) {
            $payload[$column] = $data[$column] ?? null;
        }
        $statement->execute($payload);
        return (int)$this->db()->lastInsertId();
    }

    public function insertGuarantor(int $aidId, array $person, int $order): void
    {
        $this->db()->prepare(
            'INSERT INTO ae_garantes
             (id_ayuda, id_persona, orden, nombre_snapshot, documento_snapshot)
             VALUES (:ayuda, :persona, :orden, :nombre, :documento)'
        )->execute([
            'ayuda' => $aidId,
            'persona' => (int)$person['id_persona'],
            'orden' => $order,
            'nombre' => (string)$person['nombre_exhibicion'],
            'documento' => $person['documento'] ?: null,
        ]);
    }

    public function insertCheck(int $aidId, array $check): void
    {
        $this->db()->prepare(
            'INSERT INTO ae_cheques
             (id_ayuda,banco,sucursal,localidad,codigo_postal,numero_cheque,cuenta,
              cuit_librador,fecha_emision,fecha_acreditacion,importe,devengamiento,
              endosado,electronico,observaciones)
             VALUES
             (:ayuda,:banco,:sucursal,:localidad,:codigo_postal,:numero_cheque,:cuenta,
              :cuit_librador,:fecha_emision,:fecha_acreditacion,:importe,:devengamiento,
              :endosado,:electronico,:observaciones)'
        )->execute([
            'ayuda' => $aidId,
            'banco' => $check['banco'],
            'sucursal' => $check['sucursal'] ?: null,
            'localidad' => $check['localidad'] ?: null,
            'codigo_postal' => $check['codigo_postal'] ?: null,
            'numero_cheque' => $check['numero_cheque'],
            'cuenta' => (string)($check['cuenta'] ?? ''),
            'cuit_librador' => $check['cuit_librador'] ?: null,
            'fecha_emision' => $check['fecha_emision'],
            'fecha_acreditacion' => $check['fecha_acreditacion'],
            'importe' => $check['importe'],
            'devengamiento' => $check['devengamiento'],
            'endosado' => $check['endosado'] ? 1 : 0,
            'electronico' => $check['electronico'] ? 1 : 0,
            'observaciones' => $check['observaciones'] ?: null,
        ]);
    }

    public function insertInstallment(int $aidId, array $installment): void
    {
        $this->db()->prepare(
            'INSERT INTO ae_cuotas
             (id_ayuda,numero_cuota,fecha_vencimiento,saldo_inicial,amortizacion_capital,
              devengamiento,gastos_administrativos,otros_gastos,recupero_gastos,sellado,
              seguro,importe_cuota,saldo_final,estado)
             VALUES
             (:ayuda,:numero,:vencimiento,:saldo_inicial,:amortizacion,:devengamiento,
              :gastos_admin,:otros,:recupero,:sellado,:seguro,:importe,:saldo_final,\'PENDIENTE\')'
        )->execute([
            'ayuda' => $aidId,
            'numero' => $installment['numero_cuota'],
            'vencimiento' => $installment['fecha_vencimiento'],
            'saldo_inicial' => $installment['saldo_inicial'],
            'amortizacion' => $installment['amortizacion_capital'],
            'devengamiento' => $installment['devengamiento'],
            'gastos_admin' => $installment['gastos_administrativos'],
            'otros' => $installment['otros_gastos'],
            'recupero' => $installment['recupero_gastos'],
            'sellado' => $installment['sellado'],
            'seguro' => $installment['seguro'],
            'importe' => $installment['importe_cuota'],
            'saldo_final' => $installment['saldo_final'],
        ]);
    }

    public function insertMutuo(int $aidId, int $number, array $snapshot, int $userId): void
    {
        $this->db()->prepare(
            'INSERT INTO ae_mutuos (numero_mutuo,id_ayuda,estado,datos_snapshot,generado_por)
             VALUES (:numero,:ayuda,\'VIGENTE\',:snapshot,:usuario)'
        )->execute([
            'numero' => $number,
            'ayuda' => $aidId,
            'snapshot' => json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            'usuario' => $userId,
        ]);
    }

    public function ensureSavingsAccount(int $personId): array
    {
        $this->db()->prepare(
            'INSERT IGNORE INTO cta_cuentas_ahorro
             (id_persona,tipo_cuenta,moneda,saldo,activo)
             VALUES (:persona,\'COMUN\',\'ARS\',0,1)'
        )->execute(['persona' => $personId]);

        $statement = $this->db()->prepare(
            'SELECT * FROM cta_cuentas_ahorro
             WHERE id_persona = :persona AND tipo_cuenta = \'COMUN\' AND moneda = \'ARS\'
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['persona' => $personId]);
        return $statement->fetch() ?: [];
    }

    public function creditAid(int $accountId, int $aidId, string $date, float $amount, int $userId, int $aidNumber): int
    {
        $account = $this->accountForUpdate($accountId);
        $previous = (float)$account['saldo'];
        $next = round($previous + $amount, 2);

        $this->db()->prepare(
            'UPDATE cta_cuentas_ahorro SET saldo = :saldo WHERE id_cuenta = :cuenta'
        )->execute(['saldo' => $next, 'cuenta' => $accountId]);

        $this->db()->prepare(
            'INSERT INTO cta_movimientos_ahorro
             (id_cuenta,id_ayuda,fecha_movimiento,tipo_movimiento,importe,saldo_anterior,
              saldo_posterior,concepto,creado_por)
             VALUES
             (:cuenta,:ayuda,:fecha,\'CREDITO_AYUDA\',:importe,:anterior,:posterior,:concepto,:usuario)'
        )->execute([
            'cuenta' => $accountId,
            'ayuda' => $aidId,
            'fecha' => $date,
            'importe' => $amount,
            'anterior' => $previous,
            'posterior' => $next,
            'concepto' => 'LIQUIDACIÓN AYUDA ECONÓMICA N° ' . str_pad((string)$aidNumber, 8, '0', STR_PAD_LEFT),
            'usuario' => $userId,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    public function reverseAidCredit(int $aidId, string $date, int $userId): array
    {
        $credit = $this->one(
            'SELECT * FROM cta_movimientos_ahorro
             WHERE id_ayuda = :ayuda AND tipo_movimiento = \'CREDITO_AYUDA\'
             LIMIT 1 FOR UPDATE',
            ['ayuda' => $aidId]
        );
        if (!$credit) {
            return ['reversed' => false, 'reason' => 'NO_CREDIT'];
        }

        $existing = $this->one(
            'SELECT id_movimiento FROM cta_movimientos_ahorro
             WHERE id_ayuda = :ayuda AND tipo_movimiento = \'REVERSO_AYUDA\'
             LIMIT 1',
            ['ayuda' => $aidId]
        );
        if ($existing) {
            return ['reversed' => false, 'reason' => 'ALREADY_REVERSED'];
        }

        $account = $this->accountForUpdate((int)$credit['id_cuenta']);
        $amount = (float)$credit['importe'];
        $previous = (float)$account['saldo'];
        if ($previous + 0.00001 < $amount) {
            return [
                'reversed' => false,
                'reason' => 'INSUFFICIENT_BALANCE',
                'balance' => $previous,
                'amount' => $amount,
            ];
        }

        $next = round($previous - $amount, 2);
        $this->db()->prepare(
            'UPDATE cta_cuentas_ahorro SET saldo = :saldo WHERE id_cuenta = :cuenta'
        )->execute(['saldo' => $next, 'cuenta' => (int)$credit['id_cuenta']]);

        $this->db()->prepare(
            'INSERT INTO cta_movimientos_ahorro
             (id_cuenta,id_ayuda,id_movimiento_reversa,fecha_movimiento,tipo_movimiento,
              importe,saldo_anterior,saldo_posterior,concepto,creado_por)
             VALUES
             (:cuenta,:ayuda,:reversa,:fecha,\'REVERSO_AYUDA\',:importe,:anterior,:posterior,
              :concepto,:usuario)'
        )->execute([
            'cuenta' => (int)$credit['id_cuenta'],
            'ayuda' => $aidId,
            'reversa' => (int)$credit['id_movimiento'],
            'fecha' => $date,
            'importe' => $amount,
            'anterior' => $previous,
            'posterior' => $next,
            'concepto' => 'REVERSO DE LIQUIDACIÓN DE AYUDA ECONÓMICA',
            'usuario' => $userId,
        ]);

        return ['reversed' => true, 'balance' => $next, 'amount' => $amount];
    }

    public function aidForUpdate(int $aidId): ?array
    {
        return $this->one(
            'SELECT a.*, prod.codigo AS tipo, prod.nombre AS producto_nombre,
                    prod.grupo_tasa, prod.sistema_amortizacion,
                    p.nombre_exhibicion AS socio_nombre,
                    COALESCE(pf.dni,p.cuit_cuil) AS documento,
                    asoc.id_asociado AS numero_socio
             FROM ae_ayudas a
             INNER JOIN ae_productos prod ON prod.id_producto = a.id_producto
             INNER JOIN per_personas p ON p.id_persona = a.id_persona
             LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
             INNER JOIN per_asociados asoc ON asoc.id_asociado = a.id_asociado
             WHERE a.id_ayuda = :id
             LIMIT 1 FOR UPDATE',
            ['id' => $aidId]
        );
    }

    public function detail(int $aidId): ?array
    {
        $aid = $this->one(
            'SELECT a.*, prod.codigo AS tipo, prod.nombre AS producto_nombre,
                    prod.descripcion AS producto_descripcion,
                    prod.sistema_amortizacion,
                    p.nombre_exhibicion AS socio_nombre,
                    COALESCE(pf.dni,p.cuit_cuil) AS documento,
                    asoc.id_asociado AS numero_socio,
                    cuenta.id_cuenta,
                    cuenta.saldo AS saldo_caja_ahorro_comun
             FROM ae_ayudas a
             INNER JOIN ae_productos prod ON prod.id_producto = a.id_producto
             INNER JOIN per_personas p ON p.id_persona = a.id_persona
             LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
             INNER JOIN per_asociados asoc ON asoc.id_asociado = a.id_asociado
             LEFT JOIN cta_cuentas_ahorro cuenta
                    ON cuenta.id_persona = a.id_persona
                   AND cuenta.tipo_cuenta = \'COMUN\'
                   AND cuenta.moneda = \'ARS\'
             WHERE a.id_ayuda = :id
             LIMIT 1',
            ['id' => $aidId]
        );
        if (!$aid) {
            return null;
        }

        $aid['creado_por_nombre'] = null;
        if (!empty($aid['creado_por'])) {
            $creator = IdentityConnection::get()->prepare(
                'SELECT nombre FROM idn_usuarios WHERE id_usuario = :id LIMIT 1'
            );
            $creator->execute(['id' => (int)$aid['creado_por']]);
            $creatorName = $creator->fetchColumn();
            $aid['creado_por_nombre'] = $creatorName === false ? null : (string)$creatorName;
        }

        $mutuo = $this->one(
            'SELECT id_mutuo, numero_mutuo, estado, datos_snapshot, generado_en, finalizado_en
             FROM ae_mutuos WHERE id_ayuda = :id LIMIT 1',
            ['id' => $aidId]
        );
        if ($mutuo && is_string($mutuo['datos_snapshot'] ?? null)) {
            $decoded = json_decode($mutuo['datos_snapshot'], true);
            $mutuo['datos_snapshot'] = is_array($decoded) ? $decoded : [];
        }

        return [
            'ayuda' => $aid,
            'garantes' => $this->rows(
                'SELECT id_garante,id_persona,orden,nombre_snapshot AS nombre,
                        documento_snapshot AS documento
                 FROM ae_garantes WHERE id_ayuda = :id ORDER BY orden',
                ['id' => $aidId]
            ),
            'cheques' => $this->rows(
                'SELECT * FROM ae_cheques WHERE id_ayuda = :id ORDER BY fecha_acreditacion, id_cheque',
                ['id' => $aidId]
            ),
            'cuotas' => $this->rows(
                'SELECT * FROM ae_cuotas WHERE id_ayuda = :id ORDER BY numero_cuota',
                ['id' => $aidId]
            ),
            'mutuo' => $mutuo,
            'renovacion' => $this->one(
                'SELECT r.*, nueva.numero_ayuda AS numero_ayuda_nueva
                 FROM ae_renovaciones r
                 INNER JOIN ae_ayudas nueva ON nueva.id_ayuda = r.id_ayuda_nueva
                 WHERE r.id_ayuda_origen = :id LIMIT 1',
                ['id' => $aidId]
            ),
            'movimiento_caja' => $this->rows(
                'SELECT id_movimiento,fecha_movimiento,tipo_movimiento,importe,
                        saldo_anterior,saldo_posterior,concepto,creado_en
                 FROM cta_movimientos_ahorro
                 WHERE id_ayuda = :id
                 ORDER BY id_movimiento',
                ['id' => $aidId]
            ),
        ];
    }

    public function guarantorsForAid(int $aidId): array
    {
        return $this->rows(
            'SELECT g.id_persona, g.orden, p.nombre_exhibicion,
                    COALESCE(pf.dni,p.cuit_cuil) AS documento
             FROM ae_garantes g
             INNER JOIN per_personas p ON p.id_persona = g.id_persona
             LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
             WHERE g.id_ayuda = :id ORDER BY g.orden',
            ['id' => $aidId]
        );
    }

    public function markRenewed(int $aidId, string $date): void
    {
        $this->db()->prepare(
            'UPDATE ae_ayudas
             SET estado = \'RENOVADA\', fecha_finalizacion = :fecha
             WHERE id_ayuda = :id'
        )->execute(['fecha' => $date, 'id' => $aidId]);
        $this->db()->prepare(
            'UPDATE ae_cuotas SET estado = \'RENOVADA\'
             WHERE id_ayuda = :id AND estado = \'PENDIENTE\''
        )->execute(['id' => $aidId]);
        $this->db()->prepare(
            'UPDATE ae_mutuos
             SET estado = \'FINALIZADO\', finalizado_en = NOW()
             WHERE id_ayuda = :id'
        )->execute(['id' => $aidId]);
    }

    public function insertRenewal(int $originId, int $newId, string $date, float $interest, ?string $notes, int $userId): void
    {
        $this->db()->prepare(
            'INSERT INTO ae_renovaciones
             (id_ayuda_origen,id_ayuda_nueva,fecha_renovacion,intereses_cobrados,
              observaciones,creado_por)
             VALUES (:origen,:nueva,:fecha,:intereses,:observaciones,:usuario)'
        )->execute([
            'origen' => $originId,
            'nueva' => $newId,
            'fecha' => $date,
            'intereses' => $interest,
            'observaciones' => $notes ?: null,
            'usuario' => $userId,
        ]);
    }

    public function annul(int $aidId, string $date, string $reason, int $userId): void
    {
        $this->db()->prepare(
            'UPDATE ae_ayudas
             SET estado = \'ANULADA\', fecha_finalizacion = :fecha,
                 motivo_anulacion = :motivo, anulado_por = :usuario
             WHERE id_ayuda = :id'
        )->execute([
            'fecha' => $date,
            'motivo' => $reason,
            'usuario' => $userId,
            'id' => $aidId,
        ]);
        $this->db()->prepare(
            'UPDATE ae_cuotas SET estado = \'ANULADA\'
             WHERE id_ayuda = :id AND estado = \'PENDIENTE\''
        )->execute(['id' => $aidId]);
        $this->db()->prepare(
            'UPDATE ae_mutuos SET estado = \'ANULADO\', finalizado_en = NOW()
             WHERE id_ayuda = :id'
        )->execute(['id' => $aidId]);
    }

    public function insertRate(string $group, string $date, float $tna, int $dayBase, ?string $notes, int $userId): int
    {
        $statement = $this->db()->prepare(
            'INSERT INTO ae_tasas (grupo_tasa,vigencia_desde,tna,base_dias,observaciones,creado_por)
             VALUES (:grupo,:fecha,:tna,:base_dias,:observaciones,:usuario)'
        );
        $statement->execute([
            'grupo' => $group,
            'fecha' => $date,
            'tna' => $tna,
            'base_dias' => $dayBase,
            'observaciones' => $notes ?: null,
            'usuario' => $userId,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    public function upsertQuote(string $period, string $date, float $value, string $source, ?string $notes, int $userId): int
    {
        $this->db()->prepare(
            'INSERT INTO ae_cotizaciones_dolar
             (periodo,fecha_referencia,valor_promedio,fuente,observaciones,creado_por)
             VALUES (:periodo,:fecha,:valor,:fuente,:observaciones,:usuario)
             ON DUPLICATE KEY UPDATE
                fecha_referencia = VALUES(fecha_referencia),
                valor_promedio = VALUES(valor_promedio),
                fuente = VALUES(fuente),
                observaciones = VALUES(observaciones),
                creado_por = VALUES(creado_por),
                actualizado_en = NOW()'
        )->execute([
            'periodo' => $period,
            'fecha' => $date,
            'valor' => $value,
            'fuente' => $source,
            'observaciones' => $notes ?: null,
            'usuario' => $userId,
        ]);

        $row = $this->one(
            'SELECT id_cotizacion FROM ae_cotizaciones_dolar WHERE periodo = :periodo LIMIT 1',
            ['periodo' => $period]
        );
        return (int)($row['id_cotizacion'] ?? 0);
    }

    public function isDuplicateCheck(string $bank, string $number, ?string $account): bool
    {
        $statement = $this->db()->prepare(
            'SELECT 1 FROM ae_cheques
             WHERE banco = :banco AND numero_cheque = :numero
               AND cuenta = :cuenta
             LIMIT 1'
        );
        $statement->execute([
            'banco' => $bank,
            'numero' => $number,
            'cuenta' => $account ?: '',
        ]);
        return (bool)$statement->fetchColumn();
    }

    private function accountForUpdate(int $accountId): array
    {
        $statement = $this->db()->prepare(
            'SELECT * FROM cta_cuentas_ahorro WHERE id_cuenta = :id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $accountId]);
        return $statement->fetch() ?: [];
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
        return $row ?: null;
    }
}
