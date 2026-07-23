<?php
declare(strict_types=1);

namespace App\Modules\Personas;

use App\Core\Connection;
use PDO;

final class PersonasRepository
{
    private function db(): PDO
    {
        return Connection::get();
    }

    public function list(array $filters): array
    {
        $where = [];
        $params = [];

        $status = strtoupper(trim((string)($filters['estado'] ?? 'ACTIVAS')));
        if ($status === 'ACTIVAS') {
            $where[] = 'p.activo = 1';
        } elseif ($status === 'BAJAS') {
            $where[] = 'p.activo = 0';
        }

        $type = strtoupper(trim((string)($filters['tipo'] ?? '')));
        if (in_array($type, ['FISICA', 'JURIDICA'], true)) {
            $where[] = 'p.tipo_persona = :tipo';
            $params['tipo'] = $type;
        }

        $localityId = filter_var($filters['id_localidad'] ?? null, FILTER_VALIDATE_INT);
        if ($localityId && $localityId > 0) {
            $where[] = 'p.id_localidad = :id_localidad';
            $params['id_localidad'] = (int)$localityId;
        }

        $membership = strtoupper(trim((string)($filters['asociado'] ?? '')));
        if ($membership === 'SI') {
            $where[] = 'a.id_asociado IS NOT NULL';
        } elseif ($membership === 'NO') {
            $where[] = 'a.id_asociado IS NULL';
        }

        $search = trim((string)($filters['buscar'] ?? ''));
        if ($search !== '') {
            $where[] = '(p.nombre_exhibicion LIKE :buscar_nombre
                OR pf.dni LIKE :buscar_dni
                OR p.cuit_cuil LIKE :buscar_cuit
                OR CAST(a.id_asociado AS CHAR) LIKE :buscar_socio
                OR p.email LIKE :buscar_email
                OR p.telefono LIKE :buscar_telefono)';
            $value = '%' . $search . '%';
            $params += [
                'buscar_nombre' => $value,
                'buscar_dni' => $value,
                'buscar_cuit' => $value,
                'buscar_socio' => $value,
                'buscar_email' => $value,
                'buscar_telefono' => $value,
            ];
        }

        $limit = max(1, min(500, (int)($filters['limite'] ?? 250)));
        $sql = 'SELECT
                    p.id_persona,
                    p.tipo_persona,
                    p.nombre_exhibicion,
                    p.cuit_cuil,
                    pf.dni,
                    p.email,
                    p.telefono,
                    p.domicilio,
                    p.activo,
                    p.fecha_baja,
                    p.motivo_baja,
                    p.creado_en,
                    loc.nombre AS localidad,
                    prov.nombre AS provincia,
                    a.id_asociado,
                    a.estado AS estado_asociado,
                    a.fecha_ingreso,
                    cat.nombre AS categoria,
                    suc.nombre AS sucursal
                FROM per_personas p
                LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
                LEFT JOIN sub_localidades loc ON loc.id_localidad = p.id_localidad
                LEFT JOIN sub_provincias prov ON prov.id_provincia = loc.id_provincia
                LEFT JOIN per_asociados a ON a.id_persona = p.id_persona
                LEFT JOIN sub_categorias_asociados cat ON cat.id_categoria_asociado = a.id_categoria_asociado
                LEFT JOIN sub_sucursales suc ON suc.id_sucursal = a.id_sucursal
                ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
                ORDER BY p.nombre_exhibicion ASC, p.id_persona ASC
                LIMIT ' . $limit;

        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function catalogs(): array
    {
        return [
            'paises' => $this->rows(
                "SELECT id_pais AS id,
                        UPPER(codigo_iso2) AS codigo,
                        UPPER(codigo_iso2) AS codigo_iso2,
                        UPPER(nombre) AS nombre
                 FROM sub_paises
                 WHERE activo = 1
                 ORDER BY CASE WHEN UPPER(codigo_iso2) = 'AR' THEN 0 ELSE 1 END, nombre"
            ),
            'provincias' => $this->rows(
                'SELECT id_provincia AS id,
                        id_pais,
                        UPPER(COALESCE(codigo, \'\')) AS codigo,
                        UPPER(nombre) AS nombre
                 FROM sub_provincias
                 WHERE activo = 1
                 ORDER BY id_pais, nombre'
            ),
            'localidades' => $this->rows(
                'SELECT loc.id_localidad AS id,
                        loc.id_provincia,
                        prov.id_pais,
                        UPPER(loc.nombre) AS nombre,
                        UPPER(COALESCE(loc.codigo_postal, \'\')) AS codigo_postal,
                        UPPER(prov.nombre) AS provincia
                 FROM sub_localidades loc
                 INNER JOIN sub_provincias prov ON prov.id_provincia = loc.id_provincia
                 INNER JOIN sub_paises pais ON pais.id_pais = prov.id_pais
                 WHERE loc.activo = 1
                   AND prov.activo = 1
                   AND pais.activo = 1
                 ORDER BY prov.nombre, loc.nombre'
            ),
            'zonas_geograficas' => $this->rows(
                'SELECT id_zona_geografica AS id,
                        UPPER(codigo) AS codigo,
                        UPPER(nombre) AS nombre
                 FROM sub_zonas_geograficas
                 WHERE activo = 1
                 ORDER BY nombre'
            ),
            'condiciones_iva' => $this->rows(
                'SELECT id_condicion_iva AS id,
                        UPPER(codigo) AS codigo,
                        UPPER(nombre) AS nombre
                 FROM sub_condiciones_iva
                 WHERE activo = 1
                 ORDER BY nombre'
            ),
            'relaciones_laborales' => $this->rows(
                'SELECT id_relacion_laboral AS id,
                        UPPER(codigo) AS codigo,
                        UPPER(nombre) AS nombre
                 FROM sub_relaciones_laborales
                 WHERE activo = 1
                 ORDER BY nombre'
            ),
            'tipos_societarios' => $this->rows(
                'SELECT id_tipo_societario AS id,
                        UPPER(codigo) AS codigo,
                        UPPER(nombre) AS nombre
                 FROM sub_tipos_societarios
                 WHERE activo = 1
                 ORDER BY nombre'
            ),
            'categorias_asociados' => $this->rows(
                'SELECT id_categoria_asociado AS id,
                        UPPER(codigo) AS codigo,
                        UPPER(nombre) AS nombre,
                        UPPER(COALESCE(descripcion, \'\')) AS descripcion
                 FROM sub_categorias_asociados
                 WHERE activo = 1
                 ORDER BY nombre'
            ),
            'sucursales' => $this->rows(
                'SELECT id_sucursal AS id,
                        UPPER(codigo) AS codigo,
                        UPPER(nombre) AS nombre,
                        UPPER(COALESCE(domicilio, \'\')) AS domicilio,
                        id_localidad
                 FROM sub_sucursales
                 WHERE activo = 1
                 ORDER BY nombre'
            ),
            'personas_vinculables' => $this->rows(
                'SELECT p.id_persona AS id,
                        UPPER(p.nombre_exhibicion) AS nombre,
                        p.tipo_persona,
                        UPPER(COALESCE(pf.dni, p.cuit_cuil)) AS documento
                 FROM per_personas p
                 LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
                 WHERE p.activo = 1
                 ORDER BY p.nombre_exhibicion
                 LIMIT 2000'
            ),
        ];
    }

    public function find(int $personId): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT p.*,
                    loc.nombre AS localidad_nombre,
                    loc.codigo_postal,
                    prov.id_provincia,
                    prov.nombre AS provincia_nombre
             FROM per_personas p
             LEFT JOIN sub_localidades loc ON loc.id_localidad = p.id_localidad
             LEFT JOIN sub_provincias prov ON prov.id_provincia = loc.id_provincia
             WHERE p.id_persona = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $personId]);
        $person = $statement->fetch();
        if (!$person) {
            return null;
        }

        return [
            'persona' => $person,
            'fisica' => $this->one('SELECT * FROM per_personas_fisicas WHERE id_persona = :id LIMIT 1', ['id' => $personId]),
            'juridica' => $this->one('SELECT * FROM per_personas_juridicas WHERE id_persona = :id LIMIT 1', ['id' => $personId]),
            'financieros' => $this->one('SELECT * FROM per_datos_financieros WHERE id_persona = :id LIMIT 1', ['id' => $personId]),
            'asociado' => $this->one(
                'SELECT a.*, cat.nombre AS categoria_nombre, suc.nombre AS sucursal_nombre
                 FROM per_asociados a
                 INNER JOIN sub_categorias_asociados cat ON cat.id_categoria_asociado = a.id_categoria_asociado
                 INNER JOIN sub_sucursales suc ON suc.id_sucursal = a.id_sucursal
                 WHERE a.id_persona = :id LIMIT 1',
                ['id' => $personId]
            ),
            'autorizados' => $this->links($personId, 'AUTORIZADO'),
            'beneficiarios' => $this->links($personId, 'BENEFICIARIO_FINAL'),
        ];
    }

    public function duplicateExists(string $field, string $value, ?int $excludePersonId = null): bool
    {
        $columns = [
            'dni' => ['table' => 'per_personas_fisicas', 'column' => 'dni'],
            'cuit_cuil' => ['table' => 'per_personas', 'column' => 'cuit_cuil'],
            'cbu' => ['table' => 'per_datos_financieros', 'column' => 'cbu'],
        ];
        if (!isset($columns[$field]) || $value === '') {
            return false;
        }

        $definition = $columns[$field];
        $sql = sprintf(
            'SELECT 1 FROM %s WHERE %s = :value',
            $definition['table'],
            $definition['column']
        );
        $params = ['value' => $value];
        if ($excludePersonId !== null) {
            $sql .= ' AND id_persona <> :exclude_id';
            $params['exclude_id'] = $excludePersonId;
        }
        $sql .= ' LIMIT 1';

        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
        return (bool)$statement->fetchColumn();
    }

    public function catalogValueExists(string $catalog, int $id): bool
    {
        $allowed = [
            'pais' => ['sub_paises', 'id_pais'],
            'localidad' => ['sub_localidades', 'id_localidad'],
            'zona' => ['sub_zonas_geograficas', 'id_zona_geografica'],
            'iva' => ['sub_condiciones_iva', 'id_condicion_iva'],
            'relacion_laboral' => ['sub_relaciones_laborales', 'id_relacion_laboral'],
            'tipo_societario' => ['sub_tipos_societarios', 'id_tipo_societario'],
            'categoria' => ['sub_categorias_asociados', 'id_categoria_asociado'],
            'sucursal' => ['sub_sucursales', 'id_sucursal'],
        ];
        if (!isset($allowed[$catalog])) {
            return false;
        }
        [$table, $column] = $allowed[$catalog];
        $statement = $this->db()->prepare("SELECT 1 FROM {$table} WHERE {$column} = :id AND activo = 1 LIMIT 1");
        $statement->execute(['id' => $id]);
        return (bool)$statement->fetchColumn();
    }

    public function linkablePersonExists(int $personId): bool
    {
        $statement = $this->db()->prepare('SELECT 1 FROM per_personas WHERE id_persona = :id AND activo = 1 LIMIT 1');
        $statement->execute(['id' => $personId]);
        return (bool)$statement->fetchColumn();
    }

    public function lockPersonForUpdate(int $personId): void
    {
        $statement = $this->db()->prepare(
            'SELECT id_persona FROM per_personas WHERE id_persona = :id FOR UPDATE'
        );
        $statement->execute(['id' => $personId]);
        if (!$statement->fetchColumn()) {
            throw new \RuntimeException('No se pudo bloquear la persona solicitada.');
        }
    }

    public function activeLinkImpact(int $personId): array
    {
        $statement = $this->db()->prepare(
            "SELECT v.id_vinculo,
                    v.tipo_vinculo,
                    v.fecha_desde,
                    v.fecha_hasta,
                    'TITULAR' AS rol_persona,
                    otra.id_persona AS id_otra_persona,
                    otra.nombre_exhibicion AS nombre_otra_persona,
                    COALESCE(otra_fisica.dni, otra.cuit_cuil) AS documento_otra_persona
             FROM per_vinculos v
             INNER JOIN per_personas otra ON otra.id_persona = v.id_persona_vinculada
             LEFT JOIN per_personas_fisicas otra_fisica ON otra_fisica.id_persona = otra.id_persona
             WHERE v.activo = 1
               AND v.id_persona_titular = :titular_id

             UNION ALL

             SELECT v.id_vinculo,
                    v.tipo_vinculo,
                    v.fecha_desde,
                    v.fecha_hasta,
                    'VINCULADA' AS rol_persona,
                    otra.id_persona AS id_otra_persona,
                    otra.nombre_exhibicion AS nombre_otra_persona,
                    COALESCE(otra_fisica.dni, otra.cuit_cuil) AS documento_otra_persona
             FROM per_vinculos v
             INNER JOIN per_personas otra ON otra.id_persona = v.id_persona_titular
             LEFT JOIN per_personas_fisicas otra_fisica ON otra_fisica.id_persona = otra.id_persona
             WHERE v.activo = 1
               AND v.id_persona_vinculada = :linked_id

             ORDER BY nombre_otra_persona, tipo_vinculo, id_vinculo"
        );
        $statement->execute([
            'titular_id' => $personId,
            'linked_id' => $personId,
        ]);
        $items = $statement->fetchAll();

        $asHolder = 0;
        $asLinked = 0;
        foreach ($items as $item) {
            if (($item['rol_persona'] ?? null) === 'TITULAR') {
                $asHolder++;
            } else {
                $asLinked++;
            }
        }

        return [
            'total' => count($items),
            'como_titular' => $asHolder,
            'como_vinculada' => $asLinked,
            'items' => $items,
        ];
    }

    public function deactivateActiveLinksForPerson(int $personId, string $leaveDate): int
    {
        $statement = $this->db()->prepare(
            'UPDATE per_vinculos
             SET activo = 0,
                 fecha_hasta = CASE
                     WHEN fecha_desde IS NOT NULL AND :leave_date_check < fecha_desde THEN fecha_desde
                     WHEN fecha_hasta IS NULL OR fecha_hasta > :leave_date_compare THEN :leave_date_set
                     ELSE fecha_hasta
                 END,
                 actualizado_en = NOW()
             WHERE activo = 1
               AND (id_persona_titular = :titular_id OR id_persona_vinculada = :linked_id)'
        );
        $statement->execute([
            'leave_date_check' => $leaveDate,
            'leave_date_compare' => $leaveDate,
            'leave_date_set' => $leaveDate,
            'titular_id' => $personId,
            'linked_id' => $personId,
        ]);
        return $statement->rowCount();
    }

    public function insertPerson(array $data, int $userId): int
    {
        $statement = $this->db()->prepare(
            'INSERT INTO per_personas (
                tipo_persona, nombre_exhibicion, cuit_cuil, email, telefono, telefono_alternativo,
                domicilio, id_localidad, localidad_exterior, id_pais_residencia, id_zona_geografica,
                id_condicion_iva, ingresos_brutos, actividad, residente, es_pep, sujeto_obligado,
                fecha_actualizacion_arca, observaciones, activo, creado_por, actualizado_por,
                creado_en, actualizado_en
             ) VALUES (
                :tipo_persona, :nombre_exhibicion, :cuit_cuil, :email, :telefono, :telefono_alternativo,
                :domicilio, :id_localidad, :localidad_exterior, :id_pais_residencia, :id_zona_geografica,
                :id_condicion_iva, :ingresos_brutos, :actividad, :residente, :es_pep, :sujeto_obligado,
                :fecha_actualizacion_arca, :observaciones, 1, :creado_por, :actualizado_por, NOW(), NOW()
             )'
        );
        $statement->execute($data + ['creado_por' => $userId, 'actualizado_por' => $userId]);
        return (int)$this->db()->lastInsertId();
    }

    public function updatePerson(int $personId, array $data, int $userId): void
    {
        $statement = $this->db()->prepare(
            'UPDATE per_personas SET
                tipo_persona = :tipo_persona,
                nombre_exhibicion = :nombre_exhibicion,
                cuit_cuil = :cuit_cuil,
                email = :email,
                telefono = :telefono,
                telefono_alternativo = :telefono_alternativo,
                domicilio = :domicilio,
                id_localidad = :id_localidad,
                localidad_exterior = :localidad_exterior,
                id_pais_residencia = :id_pais_residencia,
                id_zona_geografica = :id_zona_geografica,
                id_condicion_iva = :id_condicion_iva,
                ingresos_brutos = :ingresos_brutos,
                actividad = :actividad,
                residente = :residente,
                es_pep = :es_pep,
                sujeto_obligado = :sujeto_obligado,
                fecha_actualizacion_arca = :fecha_actualizacion_arca,
                observaciones = :observaciones,
                actualizado_por = :actualizado_por,
                actualizado_en = NOW()
             WHERE id_persona = :id_persona'
        );
        $statement->execute($data + ['actualizado_por' => $userId, 'id_persona' => $personId]);
    }

    public function savePhysical(int $personId, array $data): void
    {
        $statement = $this->db()->prepare(
            'INSERT INTO per_personas_fisicas (
                id_persona, nombres, apellidos, dni, fecha_nacimiento, genero, estado_civil,
                id_pais_nacionalidad, id_relacion_laboral, profesion, empleador, lugar_trabajo,
                telefono_laboral
             ) VALUES (
                :id_persona, :nombres, :apellidos, :dni, :fecha_nacimiento, :genero, :estado_civil,
                :id_pais_nacionalidad, :id_relacion_laboral, :profesion, :empleador, :lugar_trabajo,
                :telefono_laboral
             ) ON DUPLICATE KEY UPDATE
                nombres = VALUES(nombres), apellidos = VALUES(apellidos), dni = VALUES(dni),
                fecha_nacimiento = VALUES(fecha_nacimiento), genero = VALUES(genero),
                estado_civil = VALUES(estado_civil), id_pais_nacionalidad = VALUES(id_pais_nacionalidad),
                id_relacion_laboral = VALUES(id_relacion_laboral), profesion = VALUES(profesion),
                empleador = VALUES(empleador), lugar_trabajo = VALUES(lugar_trabajo),
                telefono_laboral = VALUES(telefono_laboral)'
        );
        $statement->execute($data + ['id_persona' => $personId]);
        $this->db()->prepare('DELETE FROM per_personas_juridicas WHERE id_persona = :id')->execute(['id' => $personId]);
    }

    public function saveLegal(int $personId, array $data): void
    {
        $statement = $this->db()->prepare(
            'INSERT INTO per_personas_juridicas (
                id_persona, razon_social, nombre_fantasia, id_tipo_societario, fecha_constitucion,
                numero_inscripcion, autoridad_contralor, fecha_cierre_ejercicio
             ) VALUES (
                :id_persona, :razon_social, :nombre_fantasia, :id_tipo_societario, :fecha_constitucion,
                :numero_inscripcion, :autoridad_contralor, :fecha_cierre_ejercicio
             ) ON DUPLICATE KEY UPDATE
                razon_social = VALUES(razon_social), nombre_fantasia = VALUES(nombre_fantasia),
                id_tipo_societario = VALUES(id_tipo_societario), fecha_constitucion = VALUES(fecha_constitucion),
                numero_inscripcion = VALUES(numero_inscripcion), autoridad_contralor = VALUES(autoridad_contralor),
                fecha_cierre_ejercicio = VALUES(fecha_cierre_ejercicio)'
        );
        $statement->execute($data + ['id_persona' => $personId]);
        $this->db()->prepare('DELETE FROM per_personas_fisicas WHERE id_persona = :id')->execute(['id' => $personId]);
    }

    public function saveFinancial(int $personId, array $data, int $userId): void
    {
        $statement = $this->db()->prepare(
            'INSERT INTO per_datos_financieros (
                id_persona, ingresos_mensuales, patrimonio_estimado, origen_fondos,
                perfil_transaccional, banco, cbu, alias_cbu, actualizado_por, actualizado_en
             ) VALUES (
                :id_persona, :ingresos_mensuales, :patrimonio_estimado, :origen_fondos,
                :perfil_transaccional, :banco, :cbu, :alias_cbu, :actualizado_por, NOW()
             ) ON DUPLICATE KEY UPDATE
                ingresos_mensuales = VALUES(ingresos_mensuales),
                patrimonio_estimado = VALUES(patrimonio_estimado),
                origen_fondos = VALUES(origen_fondos),
                perfil_transaccional = VALUES(perfil_transaccional),
                banco = VALUES(banco), cbu = VALUES(cbu), alias_cbu = VALUES(alias_cbu),
                actualizado_por = VALUES(actualizado_por), actualizado_en = NOW()'
        );
        $statement->execute($data + ['id_persona' => $personId, 'actualizado_por' => $userId]);
    }

    public function saveAssociate(int $personId, array $data, int $userId): int
    {
        $existing = $this->one('SELECT id_asociado FROM per_asociados WHERE id_persona = :id LIMIT 1', ['id' => $personId]);
        if ($existing) {
            $statement = $this->db()->prepare(
                'UPDATE per_asociados SET
                    fecha_ingreso = :fecha_ingreso,
                    id_categoria_asociado = :id_categoria_asociado,
                    id_sucursal = :id_sucursal,
                    estado = :estado,
                    cobra_cuota = :cobra_cuota,
                    debito_automatico = :debito_automatico,
                    fecha_alta_inaes = :fecha_alta_inaes,
                    fecha_baja = :fecha_baja,
                    motivo_baja = :motivo_baja,
                    actualizado_por = :actualizado_por,
                    actualizado_en = NOW()
                 WHERE id_persona = :id_persona'
            );
            $statement->execute($data + ['actualizado_por' => $userId, 'id_persona' => $personId]);
            return (int)$existing['id_asociado'];
        }

        $statement = $this->db()->prepare(
            'INSERT INTO per_asociados (
                id_persona, fecha_ingreso, id_categoria_asociado, id_sucursal, estado,
                cobra_cuota, debito_automatico, fecha_alta_inaes, fecha_baja, motivo_baja,
                creado_por, actualizado_por, creado_en, actualizado_en
             ) VALUES (
                :id_persona, :fecha_ingreso, :id_categoria_asociado, :id_sucursal, :estado,
                :cobra_cuota, :debito_automatico, :fecha_alta_inaes, :fecha_baja, :motivo_baja,
                :creado_por, :actualizado_por, NOW(), NOW()
             )'
        );
        $statement->execute($data + [
            'id_persona' => $personId,
            'creado_por' => $userId,
            'actualizado_por' => $userId,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    public function replaceOperationalLinks(int $personId, array $links, int $userId): void
    {
        $this->db()->prepare(
            "DELETE FROM per_vinculos
             WHERE id_persona_titular = :id
               AND tipo_vinculo IN ('AUTORIZADO', 'BENEFICIARIO_FINAL')"
        )->execute(['id' => $personId]);

        if ($links === []) {
            return;
        }

        $statement = $this->db()->prepare(
            'INSERT INTO per_vinculos (
                id_persona_titular, id_persona_vinculada, tipo_vinculo,
                porcentaje_participacion, alcance, operaciones_permitidas,
                fecha_desde, fecha_hasta, activo, observaciones, creado_por,
                creado_en, actualizado_en
             ) VALUES (
                :id_persona_titular, :id_persona_vinculada, :tipo_vinculo,
                :porcentaje_participacion, :alcance, :operaciones_permitidas,
                :fecha_desde, :fecha_hasta, :activo, :observaciones, :creado_por,
                NOW(), NOW()
             )'
        );

        foreach ($links as $link) {
            $statement->execute($link + [
                'id_persona_titular' => $personId,
                'creado_por' => $userId,
            ]);
        }
    }

    public function setActive(
        int $personId,
        bool $active,
        int $userId,
        ?string $reason,
        ?string $leaveDate
    ): void {
        $statement = $this->db()->prepare(
            'UPDATE per_personas
             SET activo = :activo,
                 fecha_baja = :fecha_baja,
                 motivo_baja = :motivo_baja,
                 actualizado_por = :usuario,
                 actualizado_en = NOW()
             WHERE id_persona = :id'
        );
        $statement->execute([
            'activo' => $active ? 1 : 0,
            'fecha_baja' => $active ? null : $leaveDate,
            'motivo_baja' => $active ? null : $reason,
            'usuario' => $userId,
            'id' => $personId,
        ]);

        if ($active) {
            $this->db()->prepare(
                "UPDATE per_asociados
                 SET estado = CASE WHEN estado = 'BAJA' THEN 'ACTIVO' ELSE estado END,
                     fecha_baja = NULL,
                     motivo_baja = NULL,
                     actualizado_por = :usuario,
                     actualizado_en = NOW()
                 WHERE id_persona = :id"
            )->execute(['usuario' => $userId, 'id' => $personId]);
        } else {
            $this->db()->prepare(
                "UPDATE per_asociados
                 SET estado = 'BAJA',
                     fecha_baja = :fecha_baja,
                     motivo_baja = :motivo,
                     actualizado_por = :usuario,
                     actualizado_en = NOW()
                 WHERE id_persona = :id"
            )->execute([
                'fecha_baja' => $leaveDate,
                'motivo' => $reason,
                'usuario' => $userId,
                'id' => $personId,
            ]);
        }
    }

    private function links(int $personId, string $type): array
    {
        $statement = $this->db()->prepare(
            'SELECT v.id_vinculo, v.id_persona_vinculada, v.tipo_vinculo,
                    v.porcentaje_participacion, v.alcance, v.operaciones_permitidas,
                    v.fecha_desde, v.fecha_hasta, v.activo, v.observaciones,
                    p.nombre_exhibicion AS nombre_vinculado,
                    COALESCE(pf.dni, p.cuit_cuil) AS documento_vinculado
             FROM per_vinculos v
             INNER JOIN per_personas p ON p.id_persona = v.id_persona_vinculada
             LEFT JOIN per_personas_fisicas pf ON pf.id_persona = p.id_persona
             WHERE v.id_persona_titular = :id AND v.tipo_vinculo = :tipo
             ORDER BY v.activo DESC, p.nombre_exhibicion'
        );
        $statement->execute(['id' => $personId, 'tipo' => $type]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $operations = json_decode((string)($row['operaciones_permitidas'] ?? ''), true);
            $row['operaciones_permitidas'] = is_array($operations) ? $operations : [];
        }
        unset($row);
        return $rows;
    }

    private function rows(string $sql): array
    {
        return $this->db()->query($sql)->fetchAll();
    }

    private function one(string $sql, array $params): ?array
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row ?: null;
    }
}
