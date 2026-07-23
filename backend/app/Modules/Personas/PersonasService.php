<?php
declare(strict_types=1);

namespace App\Modules\Personas;

use App\Core\ApiException;
use App\Core\AuditLogger;
use App\Core\Connection;
use PDOException;

final class PersonasService
{
    private const PERSON_TYPES = ['FISICA', 'JURIDICA'];
    private const GENDERS = ['FEMENINO', 'MASCULINO', 'NO_BINARIO', 'OTRO', 'NO_INFORMA'];
    private const MARITAL_STATUSES = ['SOLTERO', 'CASADO', 'DIVORCIADO', 'VIUDO', 'UNION_CONVIVENCIAL', 'NO_INFORMA'];
    private const ASSOCIATE_STATUSES = ['PENDIENTE', 'ACTIVO', 'SUSPENDIDO', 'INACTIVO', 'BAJA', 'FALLECIDO', 'RECHAZADO'];
    private const AUTHORIZED_OPERATIONS = ['CONSULTAR', 'DEPOSITAR', 'RETIRAR', 'FIRMAR', 'RETIRAR_DOCUMENTACION'];

    public function __construct(
        private readonly PersonasRepository $repository,
        private readonly AuditLogger $audit
    ) {}

    public function index(array $filters): array
    {
        return ['items' => $this->repository->list($filters)];
    }

    public function catalogs(): array
    {
        return $this->repository->catalogs();
    }

    public function detail(int $personId): array
    {
        $this->assertValidId($personId);
        $record = $this->repository->find($personId);
        if (!$record) {
            throw new ApiException('La persona solicitada no existe.', 'PERSON_NOT_FOUND', 404);
        }
        return $record;
    }

    public function linkImpact(int $personId): array
    {
        $this->detail($personId);
        $links = $this->repository->activeLinkImpact($personId);
        $aids = $this->repository->activeAidImpact($personId);
        return $links + [
            'ayudas_vigentes' => (int)$aids['total'],
            'ayudas_como_socio' => (int)$aids['como_socio'],
            'ayudas_como_garante' => (int)$aids['como_garante'],
            'ayudas_items' => $aids['items'],
        ];
    }

    public function create(array $input, array $session, string $correlationId): array
    {
        $userId = (int)$session['id_usuario'];
        $normalized = $this->validateAndNormalize($input, null);

        try {
            $personId = Connection::transaction(function () use ($normalized, $userId): int {
                $id = $this->repository->insertPerson($normalized['general'], $userId);
                $this->savePersonData($id, $normalized, $userId);
                return $id;
            });
        } catch (PDOException $error) {
            $this->throwDatabaseConflict($error);
        }

        $this->audit->record(
            $userId,
            'personas',
            'crear',
            'persona',
            $personId,
            [
                'tipo_persona' => $normalized['general']['tipo_persona'],
                'es_asociado' => $normalized['asociado'] !== null,
                'conyuge' => $normalized['conyuge'] !== null,
                'autorizados' => count($normalized['autorizados']),
                'beneficiarios' => count($normalized['beneficiarios']),
            ],
            'success',
            $correlationId
        );

        return $this->detail($personId);
    }

    public function update(int $personId, array $input, array $session, string $correlationId): array
    {
        $before = $this->detail($personId);
        $userId = (int)$session['id_usuario'];
        $normalized = $this->validateAndNormalize($input, $personId, $before['asociado'] !== null);

        try {
            Connection::transaction(function () use ($personId, $normalized, $userId): void {
                $this->repository->updatePerson($personId, $normalized['general'], $userId);
                $this->savePersonData($personId, $normalized, $userId);
            });
        } catch (PDOException $error) {
            $this->throwDatabaseConflict($error);
        }

        $this->audit->record(
            $userId,
            'personas',
            'editar',
            'persona',
            $personId,
            [
                'tipo_anterior' => $before['persona']['tipo_persona'] ?? null,
                'tipo_nuevo' => $normalized['general']['tipo_persona'],
                'conyuge' => $normalized['conyuge'] !== null,
                'autorizados' => count($normalized['autorizados']),
                'beneficiarios' => count($normalized['beneficiarios']),
            ],
            'success',
            $correlationId
        );

        return $this->detail($personId);
    }

    public function changeStatus(int $personId, array $input, array $session, string $correlationId): array
    {
        $current = $this->detail($personId);
        if (!array_key_exists('activo', $input)) {
            throw new ApiException('Indicá el estado que debe tener la persona.', 'VALIDATION_ERROR', 422, [
                'activo' => 'Obligatorio',
            ]);
        }

        $active = filter_var($input['activo'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            throw new ApiException('El estado indicado no es válido.', 'VALIDATION_ERROR', 422, [
                'activo' => 'Valor inválido',
            ]);
        }
        $reason = $this->nullableString($input['motivo'] ?? null, 255);
        $errors = [];
        $leaveDate = $active
            ? null
            : $this->date($input['fecha_baja'] ?? null, 'fecha_baja', $errors);

        if (!$active && $reason === null) {
            $errors['motivo'] = 'Obligatorio al dar de baja';
        }
        if (!$active && $leaveDate === null && !isset($errors['fecha_baja'])) {
            $errors['fecha_baja'] = 'Obligatoria al dar de baja';
        }
        if ($leaveDate !== null && $leaveDate > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            $errors['fecha_baja'] = 'La fecha de baja no puede ser posterior al día de hoy.';
        }
        if ($errors !== []) {
            throw new ApiException(
                'Revisá los datos de la baja.',
                'VALIDATION_ERROR',
                422,
                $errors
            );
        }

        $emptyImpact = [
            'total' => 0,
            'como_titular' => 0,
            'como_vinculada' => 0,
            'items' => [],
        ];
        $confirmLinks = $active
            ? false
            : filter_var(
                $input['confirmar_vinculos'] ?? false,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE
            ) === true;

        $userId = (int)$session['id_usuario'];
        $statusResult = Connection::transaction(function () use (
            $personId,
            $active,
            $userId,
            $reason,
            $leaveDate,
            $confirmLinks,
            $emptyImpact
        ): array {
            $this->repository->lockPersonForUpdate($personId);
            $aidImpact = $active
                ? ['total' => 0, 'como_socio' => 0, 'como_garante' => 0, 'items' => []]
                : $this->repository->activeAidImpact($personId);
            if (!$active && (int)$aidImpact['total'] > 0) {
                throw new ApiException(
                    'No se puede dar de baja a la persona porque interviene en ayudas económicas vigentes. Primero deben finalizarse, renovarse o anularse según corresponda.',
                    'ACTIVE_AIDS_BLOCK_DEACTIVATION',
                    409,
                    ['activo' => 'La persona tiene obligaciones financieras vigentes.']
                );
            }

            $linkImpact = $active
                ? $emptyImpact
                : $this->repository->activeLinkImpact($personId);

            if (!$active && (int)$linkImpact['total'] > 0 && !$confirmLinks) {
                throw new ApiException(
                    'La persona tiene vínculos activos. Revisalos y confirmá su cierre antes de darla de baja.',
                    'ACTIVE_LINKS_REQUIRE_CONFIRMATION',
                    409,
                    ['confirmar_vinculos' => 'Debés confirmar el cierre de los vínculos activos.']
                );
            }

            $this->repository->setActive(
                $personId,
                $active,
                $userId,
                $reason,
                $leaveDate
            );

            $deactivatedLinks = (!$active && $leaveDate !== null)
                ? $this->repository->deactivateActiveLinksForPerson($personId, $leaveDate)
                : 0;

            return [
                'impacto' => $linkImpact,
                'vinculos_desactivados' => $deactivatedLinks,
            ];
        });
        $linkImpact = $statusResult['impacto'];
        $deactivatedLinks = (int)$statusResult['vinculos_desactivados'];

        $this->audit->record(
            $userId,
            'personas',
            $active ? 'reactivar' : 'dar_baja',
            'persona',
            $personId,
            [
                'estado_anterior' => (bool)($current['persona']['activo'] ?? false),
                'motivo' => $reason,
                'fecha_baja' => $leaveDate,
                'vinculos_activos_detectados' => (int)$linkImpact['total'],
                'vinculos_desactivados' => $deactivatedLinks,
            ],
            'success',
            $correlationId
        );

        return $this->detail($personId);
    }

    private function savePersonData(int $personId, array $normalized, int $userId): void
    {
        if ($normalized['general']['tipo_persona'] === 'FISICA') {
            $this->repository->savePhysical($personId, $normalized['fisica']);
        } else {
            $this->repository->saveLegal($personId, $normalized['juridica']);
        }

        $this->repository->saveFinancial($personId, $normalized['financieros'], $userId);
        if ($normalized['asociado'] !== null) {
            $this->repository->saveAssociate($personId, $normalized['asociado'], $userId);
        }

        $this->repository->syncSpouseLink($personId, $normalized['conyuge'], $userId);
        $links = array_merge($normalized['autorizados'], $normalized['beneficiarios']);
        $this->repository->replaceOperationalLinks($personId, $links, $userId);
    }

    private function validateAndNormalize(
        array $input,
        ?int $personId,
        bool $alreadyAssociate = false
    ): array {
        $errors = [];
        $type = strtoupper(trim((string)($input['tipo_persona'] ?? 'FISICA')));
        if (!in_array($type, self::PERSON_TYPES, true)) {
            $errors['tipo_persona'] = 'Elegí persona física o jurídica.';
            $type = 'FISICA';
        }

        $generalInput = is_array($input['general'] ?? null) ? $input['general'] : [];
        $physicalInput = is_array($input['fisica'] ?? null) ? $input['fisica'] : [];
        $spouseInput = is_array($input['conyuge'] ?? null) ? $input['conyuge'] : [];
        $legalInput = is_array($input['juridica'] ?? null) ? $input['juridica'] : [];
        $financialInput = is_array($input['financieros'] ?? null) ? $input['financieros'] : [];
        $associateInput = is_array($input['asociado'] ?? null) ? $input['asociado'] : [];

        $taxId = $this->digitsOnly(
            $generalInput['cuit_cuil'] ?? null,
            11,
            'general.cuit_cuil',
            $errors,
            'El CUIT/CUIL'
        );
        if ($taxId !== null && strlen($taxId) !== 11) {
            $errors['general.cuit_cuil'] = 'El CUIT/CUIL debe tener 11 dígitos.';
        } elseif ($taxId !== null && $this->repository->duplicateExists('cuit_cuil', $taxId, $personId)) {
            $errors['general.cuit_cuil'] = 'Ya existe una persona con ese CUIT/CUIL.';
        }

        $email = $this->nullableEmail($generalInput['email'] ?? null, 180);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['general.email'] = 'El correo electrónico no es válido.';
        }

        $phone = $this->phone(
            $generalInput['telefono'] ?? null,
            'general.telefono',
            $errors
        );
        $alternatePhone = $this->phone(
            $generalInput['telefono_alternativo'] ?? null,
            'general.telefono_alternativo',
            $errors
        );
        $address = $this->nullableString($generalInput['domicilio'] ?? null, 220);
        if ($address !== null && !preg_match("~^[\\p{L}\\p{M}\\p{N}\\s.,'°ºª#()/\\-]+$~u", $address)) {
            $errors['general.domicilio'] = 'El domicilio solo admite letras, números y signos habituales.';
        }

        $grossIncomeNumber = $this->digitsOnly(
            $generalInput['ingresos_brutos'] ?? null,
            20,
            'general.ingresos_brutos',
            $errors,
            'Ingresos brutos'
        );

        $countryId = $this->catalogId($generalInput['id_pais_residencia'] ?? 1, 'pais', 'general.id_pais_residencia', $errors, true);
        $localityId = $this->catalogId($generalInput['id_localidad'] ?? null, 'localidad', 'general.id_localidad', $errors);
        $zoneId = $this->catalogId($generalInput['id_zona_geografica'] ?? null, 'zona', 'general.id_zona_geografica', $errors);
        $vatId = $this->catalogId($generalInput['id_condicion_iva'] ?? null, 'iva', 'general.id_condicion_iva', $errors);

        $name = '';
        $physical = null;
        $legal = null;
        if ($type === 'FISICA') {
            $firstNames = $this->requiredString($physicalInput['nombres'] ?? null, 120, 'fisica.nombres', $errors);
            $lastNames = $this->requiredString($physicalInput['apellidos'] ?? null, 120, 'fisica.apellidos', $errors);
            $this->validatePersonName($firstNames, 'fisica.nombres', $errors);
            $this->validatePersonName($lastNames, 'fisica.apellidos', $errors);

            $dni = $this->digitsOnly(
                $physicalInput['dni'] ?? null,
                8,
                'fisica.dni',
                $errors,
                'El DNI'
            );
            if ($dni === null || strlen($dni) < 7 || strlen($dni) > 8) {
                $errors['fisica.dni'] = 'El DNI debe tener 7 u 8 dígitos.';
            } elseif ($this->repository->duplicateExists('dni', $dni, $personId)) {
                $errors['fisica.dni'] = 'Ya existe una persona con ese DNI.';
            }

            $gender = strtoupper(trim((string)($physicalInput['genero'] ?? 'NO_INFORMA')));
            if (!in_array($gender, self::GENDERS, true)) {
                $errors['fisica.genero'] = 'El género indicado no es válido.';
                $gender = 'NO_INFORMA';
            }
            $marital = strtoupper(trim((string)($physicalInput['estado_civil'] ?? 'NO_INFORMA')));
            if (!in_array($marital, self::MARITAL_STATUSES, true)) {
                $errors['fisica.estado_civil'] = 'El estado civil indicado no es válido.';
                $marital = 'NO_INFORMA';
            }

            $nationalityId = $this->catalogId(
                $physicalInput['id_pais_nacionalidad'] ?? 1,
                'pais',
                'fisica.id_pais_nacionalidad',
                $errors,
                true
            );
            $employmentId = $this->catalogId(
                $physicalInput['id_relacion_laboral'] ?? null,
                'relacion_laboral',
                'fisica.id_relacion_laboral',
                $errors
            );

            $birthDate = $this->date(
                $physicalInput['fecha_nacimiento'] ?? null,
                'fisica.fecha_nacimiento',
                $errors
            );
            $this->notFuture($birthDate, 'fisica.fecha_nacimiento', 'La fecha de nacimiento', $errors);
            $workPhone = $this->phone(
                $physicalInput['telefono_laboral'] ?? null,
                'fisica.telefono_laboral',
                $errors
            );

            $name = trim(($lastNames ?? '') . ', ' . ($firstNames ?? ''), ', ');
            $physical = [
                'nombres' => $firstNames,
                'apellidos' => $lastNames,
                'dni' => $dni,
                'fecha_nacimiento' => $birthDate,
                'genero' => $gender,
                'estado_civil' => $marital,
                'id_pais_nacionalidad' => $nationalityId,
                'id_relacion_laboral' => $employmentId,
                'profesion' => $this->structuredString($physicalInput['profesion'] ?? null, 140, 'fisica.profesion', $errors),
                'empleador' => $this->structuredString($physicalInput['empleador'] ?? null, 180, 'fisica.empleador', $errors),
                'lugar_trabajo' => $this->structuredString($physicalInput['lugar_trabajo'] ?? null, 220, 'fisica.lugar_trabajo', $errors),
                'telefono_laboral' => $workPhone,
            ];
        } else {
            $businessName = $this->requiredString($legalInput['razon_social'] ?? null, 200, 'juridica.razon_social', $errors);
            $this->validateStructuredText($businessName, 'juridica.razon_social', $errors);
            if ($taxId === null) {
                $errors['general.cuit_cuil'] = 'El CUIT es obligatorio para una persona jurídica.';
            }
            $companyTypeId = $this->catalogId(
                $legalInput['id_tipo_societario'] ?? null,
                'tipo_societario',
                'juridica.id_tipo_societario',
                $errors
            );
            $closingDate = $this->nullableString($legalInput['fecha_cierre_ejercicio'] ?? null, 5);
            if ($closingDate !== null && !preg_match('/^(0[1-9]|[12][0-9]|3[01])\/(0[1-9]|1[0-2])$/', $closingDate)) {
                $errors['juridica.fecha_cierre_ejercicio'] = 'Usá el formato DD/MM.';
            }
            $constitutionDate = $this->date(
                $legalInput['fecha_constitucion'] ?? null,
                'juridica.fecha_constitucion',
                $errors
            );
            $this->notFuture($constitutionDate, 'juridica.fecha_constitucion', 'La fecha de constitución', $errors);

            $name = (string)($businessName ?? '');
            $legal = [
                'razon_social' => $businessName,
                'nombre_fantasia' => $this->structuredString($legalInput['nombre_fantasia'] ?? null, 180, 'juridica.nombre_fantasia', $errors),
                'id_tipo_societario' => $companyTypeId,
                'fecha_constitucion' => $constitutionDate,
                'numero_inscripcion' => $this->structuredString($legalInput['numero_inscripcion'] ?? null, 100, 'juridica.numero_inscripcion', $errors),
                'autoridad_contralor' => $this->structuredString($legalInput['autoridad_contralor'] ?? null, 160, 'juridica.autoridad_contralor', $errors),
                'fecha_cierre_ejercicio' => $closingDate,
            ];
        }

        $cbu = $this->digitsOnly(
            $financialInput['cbu'] ?? null,
            22,
            'financieros.cbu',
            $errors,
            'El CBU'
        );
        if ($cbu !== null && strlen($cbu) !== 22) {
            $errors['financieros.cbu'] = 'El CBU debe tener 22 dígitos.';
        } elseif ($cbu !== null && $this->repository->duplicateExists('cbu', $cbu, $personId)) {
            $errors['financieros.cbu'] = 'Ese CBU ya está registrado para otra persona.';
        }

        $isAssociate = $alreadyAssociate || $this->bool($associateInput['es_asociado'] ?? false);
        $associate = null;
        if ($isAssociate) {
            $categoryId = $this->catalogId(
                $associateInput['id_categoria_asociado'] ?? null,
                'categoria',
                'asociado.id_categoria_asociado',
                $errors,
                true
            );
            $branchId = $this->catalogId(
                $associateInput['id_sucursal'] ?? 1,
                'sucursal',
                'asociado.id_sucursal',
                $errors,
                true
            );
            $status = strtoupper(trim((string)($associateInput['estado'] ?? 'ACTIVO')));
            if (!in_array($status, self::ASSOCIATE_STATUSES, true)) {
                $errors['asociado.estado'] = 'El estado del asociado no es válido.';
                $status = 'ACTIVO';
            }
            $entryDate = $this->date($associateInput['fecha_ingreso'] ?? null, 'asociado.fecha_ingreso', $errors);
            if ($entryDate === null) {
                $errors['asociado.fecha_ingreso'] = 'La fecha de ingreso es obligatoria.';
            } else {
                $this->notFuture($entryDate, 'asociado.fecha_ingreso', 'La fecha de ingreso', $errors);
            }
            $inaesDate = $this->date(
                $associateInput['fecha_alta_inaes'] ?? null,
                'asociado.fecha_alta_inaes',
                $errors
            );
            $this->notFuture($inaesDate, 'asociado.fecha_alta_inaes', 'La fecha de alta en INAES', $errors);

            $leaveDate = $this->date($associateInput['fecha_baja'] ?? null, 'asociado.fecha_baja', $errors);
            if ($status === 'BAJA' && $leaveDate === null) {
                $errors['asociado.fecha_baja'] = 'Indicá la fecha de baja.';
            } elseif ($leaveDate !== null && $entryDate !== null && $leaveDate < $entryDate) {
                $errors['asociado.fecha_baja'] = 'La fecha de baja no puede ser anterior a la fecha de ingreso.';
            }
            $associate = [
                'fecha_ingreso' => $entryDate,
                'id_categoria_asociado' => $categoryId,
                'id_sucursal' => $branchId,
                'estado' => $status,
                'cobra_cuota' => $this->bool($associateInput['cobra_cuota'] ?? true) ? 1 : 0,
                'debito_automatico' => $this->bool($associateInput['debito_automatico'] ?? false) ? 1 : 0,
                'fecha_alta_inaes' => $inaesDate,
                'fecha_baja' => $status === 'BAJA' ? $leaveDate : null,
                'motivo_baja' => $status === 'BAJA' ? $this->freeText($associateInput['motivo_baja'] ?? null, 255, 'asociado.motivo_baja', $errors) : null,
            ];
        }

        $spouse = $type === 'FISICA'
            ? $this->normalizeSpouse($spouseInput, $personId, $errors)
            : null;

        $authorized = $this->normalizeLinks(
            is_array($input['autorizados'] ?? null) ? $input['autorizados'] : [],
            'AUTORIZADO',
            $personId,
            $errors
        );
        $beneficiaries = $type === 'JURIDICA'
            ? $this->normalizeLinks(
                is_array($input['beneficiarios'] ?? null) ? $input['beneficiarios'] : [],
                'BENEFICIARIO_FINAL',
                $personId,
                $errors
            )
            : [];

        $sum = array_sum(array_map(static fn (array $item): float => (float)($item['porcentaje_participacion'] ?? 0), $beneficiaries));
        if ($sum > 100.0001) {
            $errors['beneficiarios'] = 'La participación total no puede superar el 100%.';
        }

        $arcaUpdateDate = $this->date(
            $generalInput['fecha_actualizacion_arca'] ?? null,
            'general.fecha_actualizacion_arca',
            $errors
        );
        $this->notFuture(
            $arcaUpdateDate,
            'general.fecha_actualizacion_arca',
            'La fecha de actualización ARCA',
            $errors
        );
        $monthlyIncome = $this->decimal(
            $financialInput['ingresos_mensuales'] ?? null,
            'financieros.ingresos_mensuales',
            $errors
        );
        $estimatedAssets = $this->decimal(
            $financialInput['patrimonio_estimado'] ?? null,
            'financieros.patrimonio_estimado',
            $errors
        );
        $cbuAlias = $this->nullableString($financialInput['alias_cbu'] ?? null, 80);
        if ($cbuAlias !== null && !preg_match('/^[\p{L}\p{M}\p{N}._-]+$/u', $cbuAlias)) {
            $errors['financieros.alias_cbu'] = 'El alias solo admite letras, números, puntos, guiones y guion bajo.';
        }

        $localityExterior = $this->structuredString(
            $generalInput['localidad_exterior'] ?? null,
            120,
            'general.localidad_exterior',
            $errors
        );
        $activity = $this->structuredString(
            $generalInput['actividad'] ?? null,
            180,
            'general.actividad',
            $errors
        );
        $generalObservations = $this->freeText(
            $generalInput['observaciones'] ?? null,
            4000,
            'general.observaciones',
            $errors
        );
        $fundsOrigin = $this->freeText(
            $financialInput['origen_fondos'] ?? null,
            500,
            'financieros.origen_fondos',
            $errors
        );
        $transactionProfile = $this->freeText(
            $financialInput['perfil_transaccional'] ?? null,
            500,
            'financieros.perfil_transaccional',
            $errors
        );
        $bank = $this->structuredString(
            $financialInput['banco'] ?? null,
            120,
            'financieros.banco',
            $errors
        );

        if ($errors !== []) {
            throw new ApiException('Revisá los datos marcados antes de guardar.', 'VALIDATION_ERROR', 422, $errors);
        }

        return [
            'general' => [
                'tipo_persona' => $type,
                'nombre_exhibicion' => $name,
                'cuit_cuil' => $taxId,
                'email' => $email,
                'telefono' => $phone,
                'telefono_alternativo' => $alternatePhone,
                'domicilio' => $address,
                'id_localidad' => $localityId,
                'localidad_exterior' => $localityExterior,
                'id_pais_residencia' => $countryId,
                'id_zona_geografica' => $zoneId,
                'id_condicion_iva' => $vatId,
                'ingresos_brutos' => $grossIncomeNumber,
                'actividad' => $activity,
                'residente' => $this->bool($generalInput['residente'] ?? true) ? 1 : 0,
                'es_pep' => $this->bool($generalInput['es_pep'] ?? false) ? 1 : 0,
                'sujeto_obligado' => $this->bool($generalInput['sujeto_obligado'] ?? false) ? 1 : 0,
                'fecha_actualizacion_arca' => $arcaUpdateDate,
                'observaciones' => $generalObservations,
            ],
            'fisica' => $physical,
            'juridica' => $legal,
            'financieros' => [
                'ingresos_mensuales' => $monthlyIncome,
                'patrimonio_estimado' => $estimatedAssets,
                'origen_fondos' => $fundsOrigin,
                'perfil_transaccional' => $transactionProfile,
                'banco' => $bank,
                'cbu' => $cbu,
                'alias_cbu' => $cbuAlias,
            ],
            'asociado' => $associate,
            'conyuge' => $spouse,
            'autorizados' => $authorized,
            'beneficiarios' => $beneficiaries,
        ];
    }

    private function normalizeSpouse(
        array $input,
        ?int $personId,
        array &$errors
    ): ?array {
        $rawLinkedId = $input['id_persona_vinculada'] ?? null;
        if ($rawLinkedId === null || $rawLinkedId === '') {
            return null;
        }

        $linkedId = filter_var($rawLinkedId, FILTER_VALIDATE_INT);
        if (!$linkedId || $linkedId < 1) {
            $errors['conyuge.id_persona_vinculada'] = 'Seleccioná una persona física registrada.';
            return null;
        }
        $linkedId = (int)$linkedId;

        if ($personId !== null && $linkedId === $personId) {
            $errors['conyuge.id_persona_vinculada'] = 'Una persona no puede ser su propio cónyuge.';
        } elseif (!$this->repository->linkablePhysicalPersonExists($linkedId)) {
            $errors['conyuge.id_persona_vinculada'] = 'El cónyuge debe ser una persona física activa.';
        } elseif ($this->repository->spouseConflictExists($linkedId, $personId)) {
            $errors['conyuge.id_persona_vinculada'] = 'La persona seleccionada ya tiene un vínculo de cónyuge activo.';
        }

        $from = $this->date(
            $input['fecha_desde'] ?? null,
            'conyuge.fecha_desde',
            $errors
        );
        if ($from !== null && $from > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            $errors['conyuge.fecha_desde'] = 'La fecha del vínculo no puede ser futura.';
        }

        return [
            'id_persona_vinculada' => $linkedId,
            'fecha_desde' => $from,
            'observaciones' => $this->freeText($input['observaciones'] ?? null, 500, 'conyuge.observaciones', $errors),
        ];
    }

    private function normalizeLinks(
        array $items,
        string $type,
        ?int $personId,
        array &$errors
    ): array {
        $normalized = [];
        $seen = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $linkedId = filter_var($item['id_persona_vinculada'] ?? null, FILTER_VALIDATE_INT);
            $prefix = $type === 'AUTORIZADO' ? 'autorizados' : 'beneficiarios';
            if (!$linkedId || $linkedId < 1) {
                $errors["{$prefix}.{$index}.id_persona_vinculada"] = 'Seleccioná una persona registrada.';
                continue;
            }
            $linkedId = (int)$linkedId;
            if ($personId !== null && $linkedId === $personId) {
                $errors["{$prefix}.{$index}.id_persona_vinculada"] = 'Una persona no puede vincularse consigo misma.';
            } elseif (!$this->repository->linkablePersonExists($linkedId)) {
                $errors["{$prefix}.{$index}.id_persona_vinculada"] = 'La persona seleccionada no está disponible.';
            }
            if (isset($seen[$linkedId])) {
                $errors["{$prefix}.{$index}.id_persona_vinculada"] = 'La persona ya fue agregada en esta lista.';
            }
            $seen[$linkedId] = true;

            $percentage = null;
            if ($type === 'BENEFICIARIO_FINAL') {
                $percentage = $this->decimal(
                    $item['porcentaje_participacion'] ?? null,
                    "{$prefix}.{$index}.porcentaje_participacion",
                    $errors
                );
                if ($percentage === null || $percentage <= 0 || $percentage > 100) {
                    $errors["{$prefix}.{$index}.porcentaje_participacion"] = 'Ingresá un porcentaje mayor a 0 y hasta 100.';
                }
            }

            $from = $this->date($item['fecha_desde'] ?? null, "{$prefix}.{$index}.fecha_desde", $errors);
            $to = $this->date($item['fecha_hasta'] ?? null, "{$prefix}.{$index}.fecha_hasta", $errors);
            if ($from !== null && $to !== null && $to < $from) {
                $errors["{$prefix}.{$index}.fecha_hasta"] = 'La fecha hasta no puede ser anterior a la fecha desde.';
            }

            $operations = [];
            if ($type === 'AUTORIZADO') {
                foreach ((array)($item['operaciones_permitidas'] ?? []) as $operation) {
                    $operation = strtoupper(trim((string)$operation));
                    if (in_array($operation, self::AUTHORIZED_OPERATIONS, true)) {
                        $operations[] = $operation;
                    }
                }
                $operations = array_values(array_unique($operations));
            }

            $normalized[] = [
                'id_persona_vinculada' => $linkedId,
                'tipo_vinculo' => $type,
                'porcentaje_participacion' => $percentage,
                'alcance' => $this->freeText($item['alcance'] ?? null, 255, "{$prefix}.{$index}.alcance", $errors),
                'operaciones_permitidas' => $operations === [] ? null : json_encode($operations, JSON_UNESCAPED_UNICODE),
                'fecha_desde' => $from,
                'fecha_hasta' => $to,
                'activo' => $this->bool($item['activo'] ?? true) ? 1 : 0,
                'observaciones' => $this->freeText($item['observaciones'] ?? null, 500, "{$prefix}.{$index}.observaciones", $errors),
            ];
        }
        return $normalized;
    }

    private function catalogId(
        mixed $value,
        string $catalog,
        string $field,
        array &$errors,
        bool $required = false
    ): ?int {
        if ($value === null || $value === '') {
            if ($required) {
                $errors[$field] = 'Seleccioná una opción.';
            }
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if (!$id || $id < 1 || !$this->repository->catalogValueExists($catalog, (int)$id)) {
            $errors[$field] = 'La opción seleccionada no es válida.';
            return null;
        }
        return (int)$id;
    }

    private function assertValidId(int $personId): void
    {
        if ($personId < 1) {
            throw new ApiException('Indicá una persona válida.', 'INVALID_PERSON_ID', 422);
        }
    }

    private function throwDatabaseConflict(PDOException $error): never
    {
        if ((string)$error->getCode() === '23000') {
            throw new ApiException(
                'No se pudo guardar porque hay un DNI, CUIT/CUIL, CBU o relación duplicada.',
                'DUPLICATE_PERSON_DATA',
                409
            );
        }
        throw $error;
    }

    private function nullableEmail(mixed $value, int $maxLength): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }
        return mb_substr(mb_strtolower($text, 'UTF-8'), 0, $maxLength, 'UTF-8');
    }

    private function digitsOnly(
        mixed $value,
        int $maxLength,
        string $field,
        array &$errors,
        string $label
    ): ?string {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', $text)) {
            $errors[$field] = $label . ' solo admite números.';
            return null;
        }
        if (strlen($text) > $maxLength) {
            $errors[$field] = $label . ' supera la cantidad máxima de dígitos.';
            return substr($text, 0, $maxLength);
        }
        return $text;
    }

    private function phone(mixed $value, string $field, array &$errors): ?string
    {
        $phone = $this->digitsOnly($value, 20, $field, $errors, 'El teléfono');
        if ($phone !== null && strlen($phone) < 6) {
            $errors[$field] = 'El teléfono debe tener entre 6 y 20 dígitos.';
        }
        return $phone;
    }

    private function validatePersonName(?string $value, string $field, array &$errors): void
    {
        if ($value !== null && !preg_match("~^[\\p{L}\\p{M}\\s'\\-]+$~u", $value)) {
            $errors[$field] = 'Ingresá solamente letras.';
        }
    }

    private function notFuture(
        ?string $value,
        string $field,
        string $label,
        array &$errors
    ): void {
        if ($value !== null && $value > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            $errors[$field] = $label . ' no puede ser futura.';
        }
    }

    private function validateStructuredText(?string $value, string $field, array &$errors): void
    {
        if ($value !== null && !preg_match("~^[\p{L}\p{M}\p{N}\s.,'&°ºª#():/+\-]+$~u", $value)) {
            $errors[$field] = 'El campo contiene caracteres no permitidos.';
        }
    }

    private function structuredString(
        mixed $value,
        int $maxLength,
        string $field,
        array &$errors
    ): ?string {
        $text = $this->nullableString($value, $maxLength);
        $this->validateStructuredText($text, $field, $errors);
        return $text;
    }

    private function freeText(
        mixed $value,
        int $maxLength,
        string $field,
        array &$errors
    ): ?string {
        $text = $this->nullableString($value, $maxLength);
        if ($text !== null && !preg_match("~^[\p{L}\p{M}\p{N}\s.,;:'\"¿?¡!°ºª#%&@$()\[\]{}\/+_=\-]+$~u", $text)) {
            $errors[$field] = 'El campo contiene caracteres no permitidos.';
        }
        return $text;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }

        // Convención general del módulo: todos los datos textuales se almacenan en mayúsculas.
        $uppercase = mb_strtoupper($text, 'UTF-8');
        return mb_substr($uppercase, 0, $maxLength, 'UTF-8');
    }

    private function requiredString(mixed $value, int $maxLength, string $field, array &$errors): ?string
    {
        $text = $this->nullableString($value, $maxLength);
        if ($text === null) {
            $errors[$field] = 'Este campo es obligatorio.';
        }
        return $text;
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function decimal(mixed $value, string $field, array &$errors): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string)$value);
        if (!preg_match('/^\d{1,16}(?:[.,]\d{1,2})?$/', $text)) {
            $errors[$field] = 'Ingresá un número válido con hasta 2 decimales.';
            return null;
        }

        $normalized = str_replace(',', '.', $text);
        return round((float)$normalized, 2);
    }

    private function date(mixed $value, string $field, array &$errors): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text) {
            $errors[$field] = 'La fecha no es válida.';
            return null;
        }
        return $text;
    }
}
