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
        if (!$active && $reason === null) {
            throw new ApiException('Ingresá el motivo de la baja.', 'VALIDATION_ERROR', 422, [
                'motivo' => 'Obligatorio al dar de baja',
            ]);
        }

        $userId = (int)$session['id_usuario'];
        Connection::transaction(fn () => $this->repository->setActive($personId, $active, $userId, $reason));

        $this->audit->record(
            $userId,
            'personas',
            $active ? 'reactivar' : 'dar_baja',
            'persona',
            $personId,
            ['estado_anterior' => (bool)($current['persona']['activo'] ?? false), 'motivo' => $reason],
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
        $legalInput = is_array($input['juridica'] ?? null) ? $input['juridica'] : [];
        $financialInput = is_array($input['financieros'] ?? null) ? $input['financieros'] : [];
        $associateInput = is_array($input['asociado'] ?? null) ? $input['asociado'] : [];

        $taxId = $this->digits($generalInput['cuit_cuil'] ?? null, 11);
        if ($taxId !== null && strlen($taxId) !== 11) {
            $errors['general.cuit_cuil'] = 'El CUIT/CUIL debe tener 11 dígitos.';
        } elseif ($taxId !== null && $this->repository->duplicateExists('cuit_cuil', $taxId, $personId)) {
            $errors['general.cuit_cuil'] = 'Ya existe una persona con ese CUIT/CUIL.';
        }

        $email = $this->nullableString($generalInput['email'] ?? null, 180);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['general.email'] = 'El correo electrónico no es válido.';
        }

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
            $dni = $this->digits($physicalInput['dni'] ?? null, 12);
            if ($dni === null || strlen($dni) < 7) {
                $errors['fisica.dni'] = 'Ingresá un DNI válido.';
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

            $name = trim(($lastNames ?? '') . ', ' . ($firstNames ?? ''), ', ');
            $physical = [
                'nombres' => $firstNames,
                'apellidos' => $lastNames,
                'dni' => $dni,
                'fecha_nacimiento' => $this->date($physicalInput['fecha_nacimiento'] ?? null, 'fisica.fecha_nacimiento', $errors),
                'genero' => $gender,
                'estado_civil' => $marital,
                'id_pais_nacionalidad' => $nationalityId,
                'id_relacion_laboral' => $employmentId,
                'profesion' => $this->nullableString($physicalInput['profesion'] ?? null, 140),
                'empleador' => $this->nullableString($physicalInput['empleador'] ?? null, 180),
                'lugar_trabajo' => $this->nullableString($physicalInput['lugar_trabajo'] ?? null, 220),
                'telefono_laboral' => $this->nullableString($physicalInput['telefono_laboral'] ?? null, 50),
            ];
        } else {
            $businessName = $this->requiredString($legalInput['razon_social'] ?? null, 200, 'juridica.razon_social', $errors);
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
            $name = (string)($businessName ?? '');
            $legal = [
                'razon_social' => $businessName,
                'nombre_fantasia' => $this->nullableString($legalInput['nombre_fantasia'] ?? null, 180),
                'id_tipo_societario' => $companyTypeId,
                'fecha_constitucion' => $this->date($legalInput['fecha_constitucion'] ?? null, 'juridica.fecha_constitucion', $errors),
                'numero_inscripcion' => $this->nullableString($legalInput['numero_inscripcion'] ?? null, 100),
                'autoridad_contralor' => $this->nullableString($legalInput['autoridad_contralor'] ?? null, 160),
                'fecha_cierre_ejercicio' => $closingDate,
            ];
        }

        $cbu = $this->digits($financialInput['cbu'] ?? null, 22);
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
            }
            $leaveDate = $this->date($associateInput['fecha_baja'] ?? null, 'asociado.fecha_baja', $errors);
            if ($status === 'BAJA' && $leaveDate === null) {
                $errors['asociado.fecha_baja'] = 'Indicá la fecha de baja.';
            }
            $associate = [
                'fecha_ingreso' => $entryDate,
                'id_categoria_asociado' => $categoryId,
                'id_sucursal' => $branchId,
                'estado' => $status,
                'cobra_cuota' => $this->bool($associateInput['cobra_cuota'] ?? true) ? 1 : 0,
                'debito_automatico' => $this->bool($associateInput['debito_automatico'] ?? false) ? 1 : 0,
                'fecha_alta_inaes' => $this->date($associateInput['fecha_alta_inaes'] ?? null, 'asociado.fecha_alta_inaes', $errors),
                'fecha_baja' => $status === 'BAJA' ? $leaveDate : null,
                'motivo_baja' => $status === 'BAJA' ? $this->nullableString($associateInput['motivo_baja'] ?? null, 255) : null,
            ];
        }

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

        if ($errors !== []) {
            throw new ApiException('Revisá los datos marcados antes de guardar.', 'VALIDATION_ERROR', 422, $errors);
        }

        return [
            'general' => [
                'tipo_persona' => $type,
                'nombre_exhibicion' => $name,
                'cuit_cuil' => $taxId,
                'email' => $email,
                'telefono' => $this->nullableString($generalInput['telefono'] ?? null, 50),
                'telefono_alternativo' => $this->nullableString($generalInput['telefono_alternativo'] ?? null, 50),
                'domicilio' => $this->nullableString($generalInput['domicilio'] ?? null, 220),
                'id_localidad' => $localityId,
                'localidad_exterior' => $this->nullableString($generalInput['localidad_exterior'] ?? null, 120),
                'id_pais_residencia' => $countryId,
                'id_zona_geografica' => $zoneId,
                'id_condicion_iva' => $vatId,
                'ingresos_brutos' => $this->nullableString($generalInput['ingresos_brutos'] ?? null, 50),
                'actividad' => $this->nullableString($generalInput['actividad'] ?? null, 180),
                'residente' => $this->bool($generalInput['residente'] ?? true) ? 1 : 0,
                'es_pep' => $this->bool($generalInput['es_pep'] ?? false) ? 1 : 0,
                'sujeto_obligado' => $this->bool($generalInput['sujeto_obligado'] ?? false) ? 1 : 0,
                'fecha_actualizacion_arca' => $arcaUpdateDate,
                'observaciones' => $this->nullableString($generalInput['observaciones'] ?? null, 4000),
            ],
            'fisica' => $physical,
            'juridica' => $legal,
            'financieros' => [
                'ingresos_mensuales' => $monthlyIncome,
                'patrimonio_estimado' => $estimatedAssets,
                'origen_fondos' => $this->nullableString($financialInput['origen_fondos'] ?? null, 500),
                'perfil_transaccional' => $this->nullableString($financialInput['perfil_transaccional'] ?? null, 500),
                'banco' => $this->nullableString($financialInput['banco'] ?? null, 120),
                'cbu' => $cbu,
                'alias_cbu' => $this->nullableString($financialInput['alias_cbu'] ?? null, 80),
            ],
            'asociado' => $associate,
            'autorizados' => $authorized,
            'beneficiarios' => $beneficiaries,
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
                'alcance' => $this->nullableString($item['alcance'] ?? null, 255),
                'operaciones_permitidas' => $operations === [] ? null : json_encode($operations, JSON_UNESCAPED_UNICODE),
                'fecha_desde' => $from,
                'fecha_hasta' => $to,
                'activo' => $this->bool($item['activo'] ?? true) ? 1 : 0,
                'observaciones' => $this->nullableString($item['observaciones'] ?? null, 500),
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

    private function digits(mixed $value, int $maxLength): ?string
    {
        $digits = preg_replace('/\D+/', '', (string)($value ?? '')) ?? '';
        return $digits === '' ? null : substr($digits, 0, $maxLength);
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
        $normalized = str_replace(',', '.', trim((string)$value));
        if (!is_numeric($normalized) || (float)$normalized < 0) {
            $errors[$field] = 'Ingresá un importe válido, igual o mayor a cero.';
            return null;
        }
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
