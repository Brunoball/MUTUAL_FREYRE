<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Core\ApiException;
use App\Core\AuditLogger;
use App\Core\Connection;
use DateTimeImmutable;
use PDOException;

final class AyudasService
{
    public function __construct(
        private readonly AyudasRepository $repository,
        private readonly AuditLogger $audit
    ) {}

    public function index(array $filters): array
    {
        return ['items' => $this->repository->list($filters)];
    }

    public function catalogs(?string $date = null): array
    {
        $resolvedDate = $this->validDate($date ?: date('Y-m-d'), 'fecha');
        return $this->repository->catalogs($resolvedDate);
    }

    public function parameters(): array
    {
        return $this->repository->parameters();
    }

    public function currentBnaQuote(): array
    {
        return (new BnaCotizacionClient())->currentUsdBillete();
    }

    public function detail(int $aidId): array
    {
        $detail = $this->repository->detail($aidId);
        if (!$detail) {
            throw new ApiException('La ayuda económica no existe.', 'AID_NOT_FOUND', 404);
        }
        return $detail;
    }

    public function simulate(array $input): array
    {
        $prepared = $this->prepare($input);
        return $this->publicSimulation($prepared);
    }

    public function create(array $input, array $session, string $correlationId): array
    {
        $prepared = $this->prepare($input);
        $userId = (int)$session['id_usuario'];

        try {
            $created = Connection::transaction(function () use ($prepared, $userId): array {
                return $this->persistPrepared($prepared, $userId, true, null, 'NUEVA');
            });
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new ApiException(
                    'No se pudo liquidar la ayuda porque un cheque o número ya fue registrado.',
                    'DUPLICATE_FINANCIAL_RECORD',
                    409
                );
            }
            throw $error;
        }

        $this->audit->record(
            $userId,
            'ayudas',
            'liquidar',
            'ayuda_economica',
            $created['id_ayuda'],
            [
                'numero_ayuda' => $created['numero_ayuda'],
                'tipo' => $prepared['producto']['codigo'],
                'id_asociado' => $prepared['asociado']['id_asociado'],
                'capital' => $prepared['resumen']['capital_original'],
                'moneda' => $prepared['producto']['moneda'],
                'importe_acreditado_ars' => $prepared['resumen']['importe_acreditado_ars'],
            ],
            'success',
            $correlationId
        );

        return $created + ['detalle' => $this->detail($created['id_ayuda'])];
    }

    public function renew(int $aidId, array $input, array $session, string $correlationId): array
    {
        $userId = (int)$session['id_usuario'];
        $renewalDate = $this->validDate((string)($input['fecha_renovacion'] ?? date('Y-m-d')), 'fecha_renovacion');
        $term = $this->integer($input['plazo_dias'] ?? 0, 'plazo_dias', 1, 365);
        if (!in_array($term, [30, 60], true)) {
            throw new ApiException(
                'La ayuda B renovable admite plazos de 30 o 60 días.',
                'INVALID_RENEWAL_TERM',
                422,
                ['plazo_dias' => 'Elegí 30 o 60 días.']
            );
        }
        if (($input['confirmar_cobro_intereses'] ?? false) !== true) {
            throw new ApiException(
                'Confirmá que los intereses del período anterior fueron cobrados.',
                'INTEREST_CONFIRMATION_REQUIRED',
                422,
                ['confirmar_cobro_intereses' => 'Confirmación obligatoria.']
            );
        }

        $result = Connection::transaction(function () use ($aidId, $input, $renewalDate, $term, $userId): array {
            $origin = $this->repository->aidForUpdate($aidId);
            if (!$origin) {
                throw new ApiException('La ayuda económica no existe.', 'AID_NOT_FOUND', 404);
            }
            if ((string)$origin['tipo'] !== 'B') {
                throw new ApiException(
                    'Solo las ayudas tipo B pueden renovarse.',
                    'AID_NOT_RENEWABLE',
                    409
                );
            }
            if ((string)$origin['estado'] !== 'VIGENTE') {
                throw new ApiException(
                    'La ayuda ya no se encuentra vigente.',
                    'AID_NOT_ACTIVE',
                    409
                );
            }
            if ($renewalDate < (string)$origin['fecha_vencimiento']) {
                throw new ApiException(
                    'La renovación se registra al vencimiento de la ayuda.',
                    'RENEWAL_BEFORE_DUE_DATE',
                    422,
                    ['fecha_renovacion' => 'No puede ser anterior al vencimiento.']
                );
            }
            if ($renewalDate > date('Y-m-d')) {
                throw new ApiException(
                    'No se puede registrar una renovación con fecha futura.',
                    'FUTURE_RENEWAL_DATE',
                    422,
                    ['fecha_renovacion' => 'Esperá al vencimiento para renovarla.']
                );
            }

            $guarantors = $this->repository->guarantorsForAid($aidId);
            $renewalInput = [
                'tipo' => 'B',
                'id_asociado' => (int)$origin['id_asociado'],
                'fecha_solicitud' => $renewalDate,
                'fecha_liquidacion' => $renewalDate,
                'capital' => (float)$origin['capital_original'],
                'plazo_dias' => $term,
                'rubro' => (string)$origin['rubro'],
                'destino' => (string)$origin['destino'],
                'detalle' => (string)($origin['detalle'] ?? ''),
                'observaciones' => trim(
                    'RENOVACIÓN DE AYUDA N° ' . str_pad((string)$origin['numero_ayuda'], 8, '0', STR_PAD_LEFT)
                    . '. ' . (string)($input['observaciones'] ?? '')
                ),
                'tipo_garantia' => (string)$origin['tipo_garantia'],
                'garantes' => array_map(
                    static fn(array $item): int => (int)$item['id_persona'],
                    $guarantors
                ),
                'gastos_administrativos' => $input['gastos_administrativos'] ?? 0,
                'otros_gastos' => $input['otros_gastos'] ?? 0,
                'recupero_gastos' => $input['recupero_gastos'] ?? 0,
                'sellado' => $input['sellado'] ?? 0,
                'seguro' => $input['seguro'] ?? 0,
            ];

            $prepared = $this->prepare($renewalInput);
            $created = $this->persistPrepared(
                $prepared,
                $userId,
                false,
                (int)$origin['id_ayuda'],
                'RENOVACION'
            );

            $interestCollected = round((float)$origin['devengamiento_total'], 2);
            $this->repository->markRenewed((int)$origin['id_ayuda'], $renewalDate);
            $this->repository->insertRenewal(
                (int)$origin['id_ayuda'],
                $created['id_ayuda'],
                $renewalDate,
                $interestCollected,
                trim((string)($input['observaciones'] ?? '')) ?: null,
                $userId
            );

            return $created + [
                'id_ayuda_origen' => (int)$origin['id_ayuda'],
                'numero_ayuda_origen' => (int)$origin['numero_ayuda'],
                'intereses_cobrados' => $interestCollected,
            ];
        });

        $this->audit->record(
            $userId,
            'ayudas',
            'renovar',
            'ayuda_economica',
            $result['id_ayuda'],
            [
                'id_ayuda_origen' => $result['id_ayuda_origen'],
                'numero_ayuda_origen' => $result['numero_ayuda_origen'],
                'numero_ayuda_nueva' => $result['numero_ayuda'],
                'intereses_cobrados' => $result['intereses_cobrados'],
            ],
            'success',
            $correlationId
        );

        return $result + ['detalle' => $this->detail($result['id_ayuda'])];
    }

    public function annul(int $aidId, array $input, array $session, string $correlationId): array
    {
        $reason = $this->text($input['motivo'] ?? '', 'motivo', 10, 500);
        $date = $this->validDate((string)($input['fecha'] ?? date('Y-m-d')), 'fecha');
        $userId = (int)$session['id_usuario'];

        $result = Connection::transaction(function () use ($aidId, $reason, $date, $userId): array {
            $aid = $this->repository->aidForUpdate($aidId);
            if (!$aid) {
                throw new ApiException('La ayuda económica no existe.', 'AID_NOT_FOUND', 404);
            }
            if ((string)$aid['estado'] !== 'VIGENTE') {
                throw new ApiException(
                    'Solo puede anularse una ayuda vigente.',
                    'AID_NOT_ACTIVE',
                    409
                );
            }
            if ($date < (string)$aid['fecha_liquidacion'] || $date > date('Y-m-d')) {
                throw new ApiException(
                    'La fecha de anulación debe estar entre la liquidación y el día actual.',
                    'INVALID_ANNULMENT_DATE',
                    422,
                    ['fecha' => 'Revisá la fecha de anulación.']
                );
            }

            $reversal = $this->repository->reverseAidCredit($aidId, $date, $userId);
            if (($reversal['reason'] ?? '') === 'INSUFFICIENT_BALANCE') {
                throw new ApiException(
                    'No se puede anular: el importe liquidado ya no está disponible en la caja de ahorro común.',
                    'AID_FUNDS_ALREADY_WITHDRAWN',
                    409
                );
            }
            $reversalReason = (string)($reversal['reason'] ?? '');
            if ((float)$aid['importe_acreditado_ars'] > 0 && in_array($reversalReason, ['NO_CREDIT', 'ALREADY_REVERSED'], true)) {
                throw new ApiException(
                    'No se puede anular porque la acreditación no tiene un estado consistente. Revisá los movimientos de la caja común.',
                    'AID_CREDIT_INCONSISTENT',
                    409
                );
            }

            $this->repository->annul($aidId, $date, $reason, $userId);
            return [
                'id_ayuda' => $aidId,
                'numero_ayuda' => (int)$aid['numero_ayuda'],
                'estado' => 'ANULADA',
                'reverso_caja' => $reversal,
            ];
        });

        $this->audit->record(
            $userId,
            'ayudas',
            'anular',
            'ayuda_economica',
            $aidId,
            [
                'numero_ayuda' => $result['numero_ayuda'],
                'motivo' => $reason,
                'reverso_caja' => (bool)($result['reverso_caja']['reversed'] ?? false),
            ],
            'success',
            $correlationId
        );

        return $result;
    }

    public function saveRate(array $input, array $session, string $correlationId): array
    {
        $group = strtoupper(trim((string)($input['grupo_tasa'] ?? '')));
        if (!in_array($group, ['A', 'B', 'EJ', 'I'], true)) {
            throw new ApiException('Elegí un grupo de tasa válido.', 'INVALID_RATE_GROUP', 422);
        }
        $date = $this->validDate((string)($input['vigencia_desde'] ?? ''), 'vigencia_desde');
        $tna = $this->decimal($input['tna'] ?? null, 'tna', 4, false, 500);
        $dayBase = $this->integer($input['base_dias'] ?? 365, 'base_dias', 360, 365);
        if (!in_array($dayBase, [360, 365], true)) {
            throw new ApiException('La base de cálculo debe ser 360 o 365 días.', 'INVALID_DAY_BASE', 422);
        }
        $notes = $this->optionalText($input['observaciones'] ?? '', 500);
        $userId = (int)$session['id_usuario'];

        try {
            $id = $this->repository->insertRate($group, $date, $tna, $dayBase, $notes, $userId);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new ApiException(
                    'Ya existe una tasa para ese grupo y fecha. Registrá una nueva vigencia.',
                    'RATE_VERSION_EXISTS',
                    409
                );
            }
            throw $error;
        }

        $this->audit->record(
            $userId,
            'ayudas',
            'configurar_tasa',
            'tasa',
            $id,
            ['grupo_tasa' => $group, 'vigencia_desde' => $date, 'tna' => $tna, 'base_dias' => $dayBase],
            'success',
            $correlationId
        );
        return ['id_tasa' => $id, 'grupo_tasa' => $group, 'vigencia_desde' => $date, 'tna' => $tna, 'base_dias' => $dayBase];
    }

    public function saveQuote(array $input, array $session, string $correlationId): array
    {
        $date = $this->validDate((string)($input['fecha_referencia'] ?? ''), 'fecha_referencia');
        $period = substr($date, 0, 7);
        $value = $this->decimal($input['valor_promedio'] ?? null, 'valor_promedio', 6, true, 100000000);
        $source = $this->optionalText(
            $input['fuente'] ?? 'BANCO NACIÓN - PROMEDIO INFORMADO POR LA MUTUAL',
            180
        ) ?: 'BANCO NACIÓN - PROMEDIO INFORMADO POR LA MUTUAL';
        $notes = $this->optionalText($input['observaciones'] ?? '', 500);
        $userId = (int)$session['id_usuario'];

        $id = $this->repository->upsertQuote($period, $date, $value, $source, $notes, $userId);
        $this->audit->record(
            $userId,
            'ayudas',
            'configurar_cotizacion_dolar',
            'cotizacion_dolar',
            $id,
            ['periodo' => $period, 'valor_promedio' => $value, 'fuente' => $source],
            'success',
            $correlationId
        );
        return [
            'id_cotizacion' => $id,
            'periodo' => $period,
            'fecha_referencia' => $date,
            'valor_promedio' => $value,
            'fuente' => $source,
        ];
    }

    private function prepare(array $input): array
    {
        $type = strtoupper(trim((string)($input['tipo'] ?? '')));
        if (!in_array($type, ['A', 'B', 'E', 'I', 'J'], true)) {
            throw new ApiException(
                'Elegí un tipo de ayuda A, B, E, I o J.',
                'INVALID_AID_TYPE',
                422,
                ['tipo' => 'Tipo obligatorio.']
            );
        }

        $product = $this->repository->productByCode($type);
        if (!$product) {
            throw new ApiException('El producto seleccionado no está habilitado.', 'AID_PRODUCT_DISABLED', 409);
        }

        $associateId = $this->integer($input['id_asociado'] ?? 0, 'id_asociado', 1, PHP_INT_MAX);
        $associate = $this->repository->associate($associateId);
        if (!$associate || !(bool)$associate['activo'] || (string)$associate['estado'] !== 'ACTIVO') {
            throw new ApiException(
                'La ayuda solo puede liquidarse a un socio activo.',
                'ASSOCIATE_NOT_ACTIVE',
                409,
                ['id_asociado' => 'Seleccioná un socio activo.']
            );
        }

        $requestDate = $this->validDate((string)($input['fecha_solicitud'] ?? date('Y-m-d')), 'fecha_solicitud');
        $liquidationDate = $this->validDate((string)($input['fecha_liquidacion'] ?? $requestDate), 'fecha_liquidacion');
        if ($liquidationDate < $requestDate) {
            throw new ApiException(
                'La fecha de liquidación no puede ser anterior a la solicitud.',
                'INVALID_LIQUIDATION_DATE',
                422,
                ['fecha_liquidacion' => 'Revisá la fecha.']
            );
        }
        $today = date('Y-m-d');
        if ($requestDate > $today || $liquidationDate > $today) {
            throw new ApiException(
                'La solicitud y la liquidación no pueden registrarse con una fecha futura.',
                'FUTURE_LIQUIDATION_DATE',
                422,
                ['fecha_liquidacion' => 'La fecha no puede ser futura.']
            );
        }

        $rate = $this->repository->rateForDate((string)$product['grupo_tasa'], $liquidationDate);
        if (!$rate) {
            throw new ApiException(
                'No hay una tasa configurada para este producto en la fecha de liquidación.',
                'AID_RATE_NOT_CONFIGURED',
                409,
                ['tipo' => 'Configurá la tasa antes de liquidar.']
            );
        }
        $tna = round((float)$rate['tna'], 4);
        $dayBase = in_array((int)($rate['base_dias'] ?? 365), [360, 365], true)
            ? (int)$rate['base_dias']
            : 365;
        $tem = round($tna / 12, 4);
        $tea = round((pow(1 + ($tna / 1200), 12) - 1) * 100, 4);

        $fees = [
            'gastos_administrativos' => $this->money($input['gastos_administrativos'] ?? 0, 'gastos_administrativos'),
            'otros_gastos' => $this->money($input['otros_gastos'] ?? 0, 'otros_gastos'),
            'recupero_gastos' => $this->money($input['recupero_gastos'] ?? 0, 'recupero_gastos'),
            'sellado' => $this->money($input['sellado'] ?? 0, 'sellado'),
            'seguro' => $this->money($input['seguro'] ?? 0, 'seguro'),
        ];
        $feeTotal = round(array_sum($fees), 2);

        $rubro = $this->text($input['rubro'] ?? '', 'rubro', 2, 140);
        $destination = $this->text($input['destino'] ?? '', 'destino', 2, 240);
        $detail = $this->optionalText($input['detalle'] ?? '', 500);
        $notes = $this->optionalText($input['observaciones'] ?? '', 1000);
        // El informe muestra el campo, pero no aporta un catálogo cerrado. Se valida
        // como texto para respetar la denominación que use la Mutual sin inventar opciones.
        $guaranteeType = $this->text(
            $input['tipo_garantia'] ?? 'SIN GARANTIA',
            'tipo_garantia',
            2,
            80
        );
        $guarantors = $this->resolveGuarantors(
            is_array($input['garantes'] ?? null) ? $input['garantes'] : [],
            (int)$associate['id_persona'],
            $guaranteeType
        );

        $checks = [];
        $plan = [];
        $capital = 0.0;
        $capitalArs = 0.0;
        $quote = null;
        $interestTotal = 0.0;
        $creditedArs = 0.0;
        $term = 1;
        $termUnit = 'CUOTAS';
        $installments = 1;
        $periodicity = 'UNICO';
        $firstDue = $liquidationDate;
        $dueDate = $liquidationDate;
        $periodMonths = 1;

        if ($type === 'A') {
            $checks = $this->prepareChecks($input['cheques'] ?? [], $liquidationDate, $tna, $dayBase);
            $capital = round(array_sum(array_column($checks, 'importe')), 2);
            $interestTotal = round(array_sum(array_column($checks, 'devengamiento')), 2);
            $capitalArs = $capital;
            $creditedArs = round($capital - $interestTotal - $feeTotal, 2);
            if ($creditedArs <= 0) {
                throw new ApiException(
                    'El importe neto de los cheques debe ser mayor que cero.',
                    'INVALID_CHECK_NET_AMOUNT',
                    422
                );
            }
            $dueDate = max(array_column($checks, 'fecha_acreditacion'));
            $firstDue = $dueDate;
            $term = max(1, $this->daysBetween($liquidationDate, $dueDate));
            $termUnit = 'DIAS';
            $plan = [[
                'numero_cuota' => 1,
                'fecha_vencimiento' => $dueDate,
                'saldo_inicial' => $capital,
                'amortizacion_capital' => $capital,
                'devengamiento' => 0.0,
                'gastos_administrativos' => 0.0,
                'otros_gastos' => 0.0,
                'recupero_gastos' => 0.0,
                'sellado' => 0.0,
                'seguro' => 0.0,
                'importe_cuota' => $capital,
                'saldo_final' => 0.0,
            ]];
        } elseif ($type === 'B') {
            $capital = $this->money($input['capital'] ?? null, 'capital', true, 999999999999);
            $capitalArs = $capital;
            $creditedArs = $capital;
            $term = $this->integer($input['plazo_dias'] ?? 0, 'plazo_dias', 1, 365);
            if (!in_array($term, [30, 60], true)) {
                throw new ApiException(
                    'La ayuda B admite plazos de 30 o 60 días.',
                    'INVALID_B_TERM',
                    422,
                    ['plazo_dias' => 'Elegí 30 o 60 días.']
                );
            }
            $termUnit = 'DIAS';
            $dueDate = $this->addDays($liquidationDate, $term);
            $firstDue = $dueDate;
            $interestTotal = round($capital * ($tna / 100) * ($term / $dayBase), 2);
            $plan = [[
                'numero_cuota' => 1,
                'fecha_vencimiento' => $dueDate,
                'saldo_inicial' => $capital,
                'amortizacion_capital' => $capital,
                'devengamiento' => $interestTotal,
                'gastos_administrativos' => $fees['gastos_administrativos'],
                'otros_gastos' => $fees['otros_gastos'],
                'recupero_gastos' => $fees['recupero_gastos'],
                'sellado' => $fees['sellado'],
                'seguro' => $fees['seguro'],
                'importe_cuota' => round($capital + $interestTotal + $feeTotal, 2),
                'saldo_final' => 0.0,
            ]];
        } elseif ($type === 'E' || $type === 'I') {
            $capital = $this->money($input['capital'] ?? null, 'capital', true, 999999999999);
            $installments = $this->integer($input['cantidad_cuotas'] ?? 0, 'cantidad_cuotas', 1, 120);
            if ($type === 'E' && !in_array($installments, [12, 18, 24], true)) {
                throw new ApiException(
                    'La ayuda E se liquida en 12, 18 o 24 cuotas.',
                    'INVALID_E_INSTALLMENTS',
                    422,
                    ['cantidad_cuotas' => 'Elegí 12, 18 o 24 cuotas.']
                );
            }
            $periodicity = 'MENSUAL';
            $term = $installments;
            $termUnit = 'CUOTAS';
            $firstDue = $this->validDate(
                (string)($input['fecha_primer_vencimiento'] ?? $this->addMonthsClamped($liquidationDate, 1)),
                'fecha_primer_vencimiento'
            );
            if ($firstDue < $liquidationDate) {
                throw new ApiException('El primer vencimiento no puede ser anterior a la liquidación.', 'INVALID_FIRST_DUE_DATE', 422);
            }

            if ($type === 'I') {
                $quote = $this->repository->quoteForDate($liquidationDate);
                if (!$quote) {
                    throw new ApiException(
                        'No hay cotización mensual del dólar para la fecha de liquidación.',
                        'USD_QUOTE_NOT_CONFIGURED',
                        409,
                        ['tipo' => 'Cargá la cotización del mes antes de liquidar una ayuda I.']
                    );
                }
                $capitalArs = round($capital * (float)$quote['valor_promedio'], 2);
                $creditedArs = $capitalArs;
            } else {
                $capitalArs = $capital;
                $creditedArs = $capital;
            }

            $interestTotal = round($capital * ($tna / 100) * ($installments / 12), 2);
            $plan = $this->directPlan($capital, $interestTotal, $fees, $installments, $firstDue);
            $dueDate = (string)$plan[array_key_last($plan)]['fecha_vencimiento'];
        } else { // J
            $capital = $this->money($input['capital'] ?? null, 'capital', true, 999999999999);
            $capitalArs = $capital;
            $creditedArs = $capital;
            $installments = $this->integer($input['cantidad_cuotas'] ?? 0, 'cantidad_cuotas', 1, 120);
            $periodicity = strtoupper(trim((string)($input['periodicidad'] ?? 'MENSUAL')));
            if (!in_array($periodicity, ['MENSUAL', 'SEMESTRAL'], true)) {
                throw new ApiException(
                    'La ayuda J puede ser mensual o semestral.',
                    'INVALID_J_PERIODICITY',
                    422
                );
            }
            $periodMonths = $periodicity === 'SEMESTRAL' ? 6 : 1;
            $term = $installments;
            $termUnit = 'CUOTAS';
            $firstDue = $this->validDate(
                (string)($input['fecha_primer_vencimiento'] ?? $this->addMonthsClamped($liquidationDate, $periodMonths)),
                'fecha_primer_vencimiento'
            );
            if ($firstDue < $liquidationDate) {
                throw new ApiException('El primer vencimiento no puede ser anterior a la liquidación.', 'INVALID_FIRST_DUE_DATE', 422);
            }
            $plan = $this->frenchPlan($capital, $tna, $fees, $installments, $firstDue, $periodMonths);
            $interestTotal = round(array_sum(array_column($plan, 'devengamiento')), 2);
            $dueDate = (string)$plan[array_key_last($plan)]['fecha_vencimiento'];
        }

        $totalRepay = $type === 'A'
            ? $capital
            : round($capital + $interestTotal + $feeTotal, 2);
        $cftFlows = $type === 'A'
            ? array_map(
                static fn(array $check): array => [
                    'fecha_vencimiento' => $check['fecha_acreditacion'],
                    'importe_cuota' => $check['importe'],
                ],
                $checks
            )
            : $plan;
        $cft = $this->calculateCft(
            $type === 'A' ? $creditedArs : $capital,
            $cftFlows,
            $liquidationDate
        );

        return [
            'producto' => $product,
            'asociado' => $associate,
            'tasa' => $rate,
            'cotizacion' => $quote,
            'garantes' => $guarantors,
            'cheques' => $checks,
            'cuotas' => $plan,
            'campos' => [
                'fecha_solicitud' => $requestDate,
                'fecha_liquidacion' => $liquidationDate,
                'plazo_cantidad' => $term,
                'plazo_unidad' => $termUnit,
                'cantidad_cuotas' => $installments,
                'periodicidad' => $periodicity,
                'fecha_primer_vencimiento' => $firstDue,
                'fecha_vencimiento' => $dueDate,
                'rubro' => $rubro,
                'destino' => $destination,
                'detalle' => $detail,
                'observaciones' => $notes,
                'tipo_garantia' => $guaranteeType,
            ],
            'resumen' => [
                'capital_original' => round($capital, 2),
                'capital_equivalente_ars' => round($capitalArs, 2),
                'cotizacion_dolar' => $quote ? round((float)$quote['valor_promedio'], 6) : null,
                'tna' => $tna,
                'tem' => $tem,
                'tea' => $tea,
                'cft' => $cft,
                'base_dias' => $dayBase,
                'devengamiento_total' => round($interestTotal, 2),
                ...$fees,
                'total_a_devolver' => $totalRepay,
                'importe_acreditado_ars' => round($creditedArs, 2),
            ],
        ];
    }

    private function persistPrepared(
        array $prepared,
        int $userId,
        bool $creditFunds,
        ?int $originAidId,
        string $operationType
    ): array {
        $aidNumber = $this->repository->nextNumber('AYUDA');
        $requestNumber = $this->repository->nextNumber('SOLICITUD');
        $mutuoNumber = $this->repository->nextNumber('MUTUO');

        $summary = $prepared['resumen'];
        $fields = $prepared['campos'];
        $aidId = $this->repository->insertAid([
            'numero_ayuda' => $aidNumber,
            'numero_solicitud' => $requestNumber,
            'id_producto' => (int)$prepared['producto']['id_producto'],
            'id_persona' => (int)$prepared['asociado']['id_persona'],
            'id_asociado' => (int)$prepared['asociado']['id_asociado'],
            'id_ayuda_origen' => $originAidId,
            'tipo_operacion' => $operationType,
            'fecha_solicitud' => $fields['fecha_solicitud'],
            'fecha_liquidacion' => $fields['fecha_liquidacion'],
            'moneda' => (string)$prepared['producto']['moneda'],
            'capital_original' => $summary['capital_original'],
            'cotizacion_dolar' => $summary['cotizacion_dolar'],
            'capital_equivalente_ars' => $summary['capital_equivalente_ars'],
            'plazo_cantidad' => $fields['plazo_cantidad'],
            'plazo_unidad' => $fields['plazo_unidad'],
            'cantidad_cuotas' => $fields['cantidad_cuotas'],
            'periodicidad' => $fields['periodicidad'],
            'fecha_primer_vencimiento' => $fields['fecha_primer_vencimiento'],
            'fecha_vencimiento' => $fields['fecha_vencimiento'],
            'tna' => $summary['tna'],
            'tem' => $summary['tem'],
            'tea' => $summary['tea'],
            'cft' => $summary['cft'],
            'base_dias' => $summary['base_dias'],
            'rubro' => $fields['rubro'],
            'destino' => $fields['destino'],
            'detalle' => $fields['detalle'],
            'observaciones' => $fields['observaciones'],
            'tipo_garantia' => $fields['tipo_garantia'],
            'devengamiento_total' => $summary['devengamiento_total'],
            'gastos_administrativos' => $summary['gastos_administrativos'],
            'otros_gastos' => $summary['otros_gastos'],
            'recupero_gastos' => $summary['recupero_gastos'],
            'sellado' => $summary['sellado'],
            'seguro' => $summary['seguro'],
            'total_a_devolver' => $summary['total_a_devolver'],
            'importe_acreditado_ars' => $creditFunds ? $summary['importe_acreditado_ars'] : 0,
            'medio_desembolso' => 'CAJA_AHORRO_COMUN',
            'estado' => 'VIGENTE',
            'creado_por' => $userId,
        ]);

        foreach ($prepared['garantes'] as $index => $guarantor) {
            $this->repository->insertGuarantor($aidId, $guarantor, $index + 1);
        }
        foreach ($prepared['cheques'] as $check) {
            $this->repository->insertCheck($aidId, $check);
        }
        foreach ($prepared['cuotas'] as $installment) {
            $this->repository->insertInstallment($aidId, $installment);
        }

        $snapshot = $this->mutuoSnapshot(
            $aidNumber,
            $requestNumber,
            $mutuoNumber,
            $prepared,
            $operationType,
            $originAidId
        );
        $this->repository->insertMutuo($aidId, $mutuoNumber, $snapshot, $userId);

        $accountMovementId = null;
        $accountBalance = null;
        if ($creditFunds && (float)$summary['importe_acreditado_ars'] > 0) {
            $account = $this->repository->ensureSavingsAccount((int)$prepared['asociado']['id_persona']);
            $accountMovementId = $this->repository->creditAid(
                (int)$account['id_cuenta'],
                $aidId,
                $fields['fecha_liquidacion'],
                (float)$summary['importe_acreditado_ars'],
                $userId,
                $aidNumber
            );
            $accountBalance = round((float)$account['saldo'] + (float)$summary['importe_acreditado_ars'], 2);
        }

        return [
            'id_ayuda' => $aidId,
            'numero_ayuda' => $aidNumber,
            'numero_solicitud' => $requestNumber,
            'numero_mutuo' => $mutuoNumber,
            'id_movimiento_caja' => $accountMovementId,
            'saldo_caja_ahorro_comun' => $accountBalance,
        ];
    }

    private function resolveGuarantors(array $ids, int $beneficiaryPersonId, string $guaranteeType): array
    {
        $normalized = [];
        foreach ($ids as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id && $id > 0) {
                $normalized[] = (int)$id;
            }
        }
        if (count($normalized) !== count(array_unique($normalized))) {
            throw new ApiException(
                'Una misma persona no puede ocupar los dos lugares de garante.',
                'DUPLICATE_GUARANTOR',
                422,
                ['garantes' => 'Elegí personas distintas.']
            );
        }
        $normalized = array_values(array_unique($normalized));
        if (count($normalized) > 2) {
            throw new ApiException('Solo pueden registrarse hasta dos garantes.', 'TOO_MANY_GUARANTORS', 422);
        }
        $people = [];
        foreach ($normalized as $personId) {
            if ($personId === $beneficiaryPersonId) {
                throw new ApiException('El socio no puede ser su propio garante.', 'SELF_GUARANTOR', 422);
            }
            $person = $this->repository->person($personId);
            if (!$person || !(bool)$person['activo']) {
                throw new ApiException('Uno de los garantes no está activo.', 'GUARANTOR_NOT_ACTIVE', 409);
            }
            $people[] = $person;
        }
        return $people;
    }

    private function prepareChecks(mixed $source, string $liquidationDate, float $tna, int $dayBase): array
    {
        if (!is_array($source) || $source === []) {
            throw new ApiException(
                'La ayuda A requiere al menos un cheque.',
                'CHECK_REQUIRED',
                422,
                ['cheques' => 'Agregá un cheque.']
            );
        }
        if (count($source) > 100) {
            throw new ApiException('No se pueden cargar más de 100 cheques por ayuda.', 'TOO_MANY_CHECKS', 422);
        }

        $checks = [];
        $localKeys = [];
        foreach ($source as $index => $item) {
            if (!is_array($item)) {
                throw new ApiException('Los datos de un cheque no son válidos.', 'INVALID_CHECK', 422);
            }
            $bank = $this->text($item['banco'] ?? '', "cheques.{$index}.banco", 2, 140);
            $number = $this->text($item['numero_cheque'] ?? '', "cheques.{$index}.numero_cheque", 1, 80);
            $account = $this->optionalText($item['cuenta'] ?? '', 80) ?? '';
            $issueDate = $this->validDate((string)($item['fecha_emision'] ?? $liquidationDate), "cheques.{$index}.fecha_emision");
            $accreditationDate = $this->validDate((string)($item['fecha_acreditacion'] ?? ''), "cheques.{$index}.fecha_acreditacion");
            if ($accreditationDate < $liquidationDate || $accreditationDate < $issueDate) {
                throw new ApiException(
                    'La acreditación del cheque no puede ser anterior a su emisión o a la liquidación.',
                    'INVALID_CHECK_DATE',
                    422,
                    ["cheques.{$index}.fecha_acreditacion" => 'Revisá la fecha.']
                );
            }
            $amount = $this->money($item['importe'] ?? null, "cheques.{$index}.importe", true, 999999999999);
            $key = mb_strtoupper($bank) . '|' . mb_strtoupper($number) . '|' . mb_strtoupper((string)$account);
            if (isset($localKeys[$key]) || $this->repository->isDuplicateCheck($bank, $number, $account)) {
                throw new ApiException(
                    "El cheque {$number} del banco {$bank} ya fue registrado.",
                    'DUPLICATE_CHECK',
                    409
                );
            }
            $localKeys[$key] = true;
            $days = max(0, $this->daysBetween($liquidationDate, $accreditationDate));
            $checks[] = [
                'banco' => $bank,
                'sucursal' => $this->optionalText($item['sucursal'] ?? '', 120),
                'localidad' => $this->optionalText($item['localidad'] ?? '', 140),
                'codigo_postal' => $this->optionalText($item['codigo_postal'] ?? '', 20),
                'numero_cheque' => $number,
                'cuenta' => $account,
                'cuit_librador' => $this->optionalText($item['cuit_librador'] ?? '', 20),
                'fecha_emision' => $issueDate,
                'fecha_acreditacion' => $accreditationDate,
                'importe' => $amount,
                'devengamiento' => round($amount * ($tna / 100) * ($days / $dayBase), 2),
                'endosado' => (bool)($item['endosado'] ?? true),
                'electronico' => (bool)($item['electronico'] ?? false),
                'observaciones' => $this->optionalText($item['observaciones'] ?? '', 500),
            ];
        }
        return $checks;
    }

    private function directPlan(float $capital, float $interestTotal, array $fees, int $count, string $firstDue): array
    {
        $capitalParts = $this->splitAmount($capital, $count);
        $interestParts = $this->splitAmount($interestTotal, $count);
        $feeParts = [];
        foreach ($fees as $key => $value) {
            $feeParts[$key] = $this->splitAmount((float)$value, $count);
        }

        $plan = [];
        $balance = $capital;
        for ($index = 0; $index < $count; $index++) {
            $principal = $capitalParts[$index];
            $initial = round($balance, 2);
            $balance = round(max(0, $balance - $principal), 2);
            $rowFees = [];
            foreach ($feeParts as $key => $parts) {
                $rowFees[$key] = $parts[$index];
            }
            $plan[] = [
                'numero_cuota' => $index + 1,
                'fecha_vencimiento' => $this->addMonthsClamped($firstDue, $index),
                'saldo_inicial' => $initial,
                'amortizacion_capital' => $principal,
                'devengamiento' => $interestParts[$index],
                ...$rowFees,
                'importe_cuota' => round($principal + $interestParts[$index] + array_sum($rowFees), 2),
                'saldo_final' => $balance,
            ];
        }
        return $plan;
    }

    private function frenchPlan(
        float $capital,
        float $tna,
        array $fees,
        int $count,
        string $firstDue,
        int $periodMonths
    ): array {
        $periodRate = ($tna / 100) * ($periodMonths / 12);
        $basePayment = $periodRate > 0
            ? $capital * ($periodRate * pow(1 + $periodRate, $count))
                / (pow(1 + $periodRate, $count) - 1)
            : $capital / $count;
        $feeParts = [];
        foreach ($fees as $key => $value) {
            $feeParts[$key] = $this->splitAmount((float)$value, $count);
        }

        $plan = [];
        $balance = $capital;
        for ($index = 0; $index < $count; $index++) {
            $initial = round($balance, 2);
            $interest = round($initial * $periodRate, 2);
            $principal = $index === $count - 1
                ? $initial
                : round(max(0, $basePayment - $interest), 2);
            if ($principal > $initial) {
                $principal = $initial;
            }
            $balance = round(max(0, $balance - $principal), 2);
            $rowFees = [];
            foreach ($feeParts as $key => $parts) {
                $rowFees[$key] = $parts[$index];
            }
            $plan[] = [
                'numero_cuota' => $index + 1,
                'fecha_vencimiento' => $this->addMonthsClamped($firstDue, $index * $periodMonths),
                'saldo_inicial' => $initial,
                'amortizacion_capital' => $principal,
                'devengamiento' => $interest,
                ...$rowFees,
                'importe_cuota' => round($principal + $interest + array_sum($rowFees), 2),
                'saldo_final' => $balance,
            ];
        }
        return $plan;
    }

    /**
     * Calcula un CFT anual efectivo a partir del desembolso neto y de los flujos
     * reales de vencimiento. Para A usa cada cheque por separado; para los demás
     * productos usa el plan de cuotas. Así no se aproxima una cartera de cheques
     * completa como si todo venciera en la última fecha.
     */
    private function calculateCft(float $netAmount, array $flows, string $liquidationDate): float
    {
        if ($netAmount <= 0 || $flows === []) {
            return 0.0;
        }

        $normalized = [];
        foreach ($flows as $flow) {
            $amount = round((float)($flow['importe_cuota'] ?? 0), 2);
            $date = (string)($flow['fecha_vencimiento'] ?? '');
            if ($amount <= 0 || $date === '') {
                continue;
            }
            $normalized[] = [
                'amount' => $amount,
                'days' => max(0, $this->daysBetween($liquidationDate, $date)),
            ];
        }
        if ($normalized === []) {
            return 0.0;
        }

        $total = array_sum(array_column($normalized, 'amount'));
        if ($total <= $netAmount + 0.00001) {
            return 0.0;
        }

        $presentValue = static function (float $annualRate) use ($normalized): float {
            $value = 0.0;
            foreach ($normalized as $flow) {
                $value += $flow['amount'] / pow(1 + $annualRate, $flow['days'] / 365);
            }
            return $value;
        };

        $low = 0.0;
        $high = 1.0;
        while ($high < 99.99 && $presentValue($high) > $netAmount) {
            $high = min(99.99, $high * 2);
        }
        if ($presentValue($high) > $netAmount) {
            return 9999.0;
        }

        for ($iteration = 0; $iteration < 160; $iteration++) {
            $mid = ($low + $high) / 2;
            if ($presentValue($mid) > $netAmount) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return round(min(9999, max(0, (($low + $high) / 2) * 100)), 4);
    }

    private function publicSimulation(array $prepared): array
    {
        return [
            'producto' => [
                'codigo' => $prepared['producto']['codigo'],
                'nombre' => $prepared['producto']['nombre'],
                'moneda' => $prepared['producto']['moneda'],
                'sistema_amortizacion' => $prepared['producto']['sistema_amortizacion'],
            ],
            'socio' => [
                'id_asociado' => (int)$prepared['asociado']['id_asociado'],
                'nombre' => $prepared['asociado']['nombre_exhibicion'],
                'documento' => $prepared['asociado']['documento'],
            ],
            'tasa' => $prepared['tasa'],
            'cotizacion' => $prepared['cotizacion'],
            'resumen' => $prepared['resumen'],
            'campos' => $prepared['campos'],
            'cuotas' => $prepared['cuotas'],
            'cheques' => $prepared['cheques'],
            'garantes' => array_map(
                static fn(array $item): array => [
                    'id_persona' => (int)$item['id_persona'],
                    'nombre' => $item['nombre_exhibicion'],
                    'documento' => $item['documento'],
                ],
                $prepared['garantes']
            ),
        ];
    }

    private function mutuoSnapshot(
        int $aidNumber,
        int $requestNumber,
        int $mutuoNumber,
        array $prepared,
        string $operationType,
        ?int $originAidId
    ): array {
        return [
            'version' => 1,
            'numero_mutuo' => $mutuoNumber,
            'numero_ayuda' => $aidNumber,
            'numero_solicitud' => $requestNumber,
            'tipo_operacion' => $operationType,
            'id_ayuda_origen' => $originAidId,
            'producto' => [
                'codigo' => $prepared['producto']['codigo'],
                'nombre' => $prepared['producto']['nombre'],
                'moneda' => $prepared['producto']['moneda'],
                'sistema_amortizacion' => $prepared['producto']['sistema_amortizacion'],
            ],
            'socio' => [
                'numero_socio' => (int)$prepared['asociado']['id_asociado'],
                'nombre' => $prepared['asociado']['nombre_exhibicion'],
                'documento' => $prepared['asociado']['documento'],
            ],
            'garantes' => array_map(
                static fn(array $item): array => [
                    'nombre' => $item['nombre_exhibicion'],
                    'documento' => $item['documento'],
                ],
                $prepared['garantes']
            ),
            'condiciones' => $prepared['campos'] + $prepared['resumen'],
            'cuotas' => $prepared['cuotas'],
            'cheques' => $prepared['cheques'],
            'leyenda' => 'Documento operativo generado con los datos de la liquidación. El texto contractual definitivo debe reemplazarse por la plantilla legal aprobada por la Mutual y su asesoría jurídica.',
            'firmas' => ['socio', 'garantes', 'mutual'],
            'generado_en' => date(DATE_ATOM),
        ];
    }

    private function splitAmount(float $amount, int $count): array
    {
        $amount = round($amount, 2);
        $base = floor(($amount / $count) * 100) / 100;
        $parts = array_fill(0, $count, $base);
        $parts[$count - 1] = round($amount - ($base * ($count - 1)), 2);
        return $parts;
    }

    private function money(mixed $value, string $field, bool $positive = false, float $maximum = 999999999999): float
    {
        return $this->decimal($value, $field, 2, $positive, $maximum);
    }

    private function decimal(
        mixed $value,
        string $field,
        int $scale,
        bool $positive = false,
        float $maximum = 999999999999
    ): float {
        if (is_string($value)) {
            $normalized = preg_replace('/[^0-9,.-]/', '', trim($value)) ?? '';
            if (str_contains($normalized, ',')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            }
            $value = $normalized;
        }
        if (!is_numeric($value)) {
            throw new ApiException('Ingresá un importe válido.', 'INVALID_AMOUNT', 422, [$field => 'Importe inválido.']);
        }
        $amount = round((float)$value, $scale);
        if (($positive && $amount <= 0) || (!$positive && $amount < 0) || $amount > $maximum) {
            throw new ApiException('El importe está fuera del rango permitido.', 'INVALID_AMOUNT', 422, [$field => 'Revisá el importe.']);
        }
        return $amount;
    }

    private function integer(mixed $value, string $field, int $minimum, int $maximum): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $minimum || $number > $maximum) {
            throw new ApiException('Ingresá un valor entero válido.', 'INVALID_INTEGER', 422, [$field => 'Valor inválido.']);
        }
        return (int)$number;
    }

    private function text(mixed $value, string $field, int $minimum, int $maximum): string
    {
        $text = mb_strtoupper(trim((string)$value), 'UTF-8');
        $length = mb_strlen($text, 'UTF-8');
        if ($length < $minimum || $length > $maximum) {
            throw new ApiException('Completá los datos obligatorios.', 'VALIDATION_ERROR', 422, [$field => 'Campo obligatorio o demasiado extenso.']);
        }
        return $text;
    }

    private function optionalText(mixed $value, int $maximum): ?string
    {
        $text = mb_strtoupper(trim((string)$value), 'UTF-8');
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text, 'UTF-8') > $maximum) {
            throw new ApiException('Uno de los textos es demasiado extenso.', 'TEXT_TOO_LONG', 422);
        }
        return $text;
    }

    private function validDate(string $value, string $field): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new ApiException('Ingresá una fecha válida.', 'INVALID_DATE', 422, [$field => 'Fecha inválida.']);
        }
        return $date->format('Y-m-d');
    }

    private function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
    }

    private function addMonthsClamped(string $date, int $months): string
    {
        $source = new DateTimeImmutable($date);
        $year = (int)$source->format('Y');
        $month = (int)$source->format('n');
        $day = (int)$source->format('j');
        $targetIndex = ($year * 12 + ($month - 1)) + $months;
        $targetYear = intdiv($targetIndex, 12);
        $targetMonth = ($targetIndex % 12) + 1;
        $lastDay = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $targetYear, $targetMonth)))
            ->modify('last day of this month')
            ->format('j');
        return sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, min($day, $lastDay));
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
    }
}
