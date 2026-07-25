<?php
declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Connection;
use PDO;

final class DashboardRepository
{
    private function db(): PDO
    {
        return Connection::get();
    }

    public function summary(): array
    {
        $statement = $this->db()->query(
            "SELECT
                (SELECT COUNT(*) FROM per_personas WHERE activo = 1) AS personas_activas,
                (SELECT COUNT(*) FROM per_personas WHERE activo = 1 AND tipo_persona = 'FISICA') AS personas_fisicas,
                (SELECT COUNT(*) FROM per_personas WHERE activo = 1 AND tipo_persona = 'JURIDICA') AS personas_juridicas,
                (SELECT COUNT(*) FROM per_asociados WHERE estado = 'ACTIVO') AS asociados_activos,
                (SELECT COUNT(*) FROM per_asociados) AS asociados_totales,
                (SELECT COUNT(*) FROM per_vinculos WHERE activo = 1) AS vinculos_activos,
                (SELECT COUNT(*) FROM ae_ayudas WHERE estado = 'VIGENTE') AS ayudas_vigentes,
                (SELECT COALESCE(SUM(capital_equivalente_ars), 0) FROM ae_ayudas WHERE estado = 'VIGENTE') AS capital_vigente_ars,
                (SELECT COALESCE(SUM(CASE WHEN a.moneda = 'USD' THEN c.importe_cuota * COALESCE(a.cotizacion_dolar, 0) ELSE c.importe_cuota END), 0)
                   FROM ae_cuotas c
                   INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
                  WHERE a.estado = 'VIGENTE' AND c.estado = 'PENDIENTE') AS cartera_pendiente_ars,
                (SELECT COUNT(*)
                   FROM ae_cuotas c
                   INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
                  WHERE a.estado = 'VIGENTE'
                    AND c.estado = 'PENDIENTE'
                    AND c.fecha_vencimiento < CURDATE()) AS cuotas_vencidas,
                (SELECT COALESCE(SUM(CASE WHEN a.moneda = 'USD' THEN c.importe_cuota * COALESCE(a.cotizacion_dolar, 0) ELSE c.importe_cuota END), 0)
                   FROM ae_cuotas c
                   INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
                  WHERE a.estado = 'VIGENTE'
                    AND c.estado = 'PENDIENTE'
                    AND c.fecha_vencimiento < CURDATE()) AS importe_vencido_ars,
                (SELECT COUNT(*)
                   FROM ae_cuotas c
                   INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
                  WHERE a.estado = 'VIGENTE'
                    AND c.estado = 'PENDIENTE'
                    AND c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS cuotas_proximas_7,
                (SELECT COALESCE(SUM(CASE WHEN a.moneda = 'USD' THEN c.importe_cuota * COALESCE(a.cotizacion_dolar, 0) ELSE c.importe_cuota END), 0)
                   FROM ae_cuotas c
                   INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
                  WHERE a.estado = 'VIGENTE'
                    AND c.estado = 'PENDIENTE'
                    AND c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS importe_proximo_7_ars,
                (SELECT COUNT(*)
                   FROM ae_cuotas c
                   INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
                  WHERE a.estado = 'VIGENTE'
                    AND c.estado = 'PENDIENTE'
                    AND c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS cuotas_proximas_30,
                (SELECT COALESCE(SUM(CASE WHEN a.moneda = 'USD' THEN c.importe_cuota * COALESCE(a.cotizacion_dolar, 0) ELSE c.importe_cuota END), 0)
                   FROM ae_cuotas c
                   INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
                  WHERE a.estado = 'VIGENTE'
                    AND c.estado = 'PENDIENTE'
                    AND c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS importe_proximo_30_ars,
                (SELECT COUNT(*) FROM cta_cuentas_ahorro WHERE activo = 1) AS cuentas_ahorro_activas,
                (SELECT COALESCE(SUM(saldo), 0) FROM cta_cuentas_ahorro WHERE activo = 1 AND moneda = 'ARS') AS saldo_cuentas_ars,
                (SELECT COALESCE(SUM(saldo), 0) FROM cta_cuentas_ahorro WHERE activo = 1 AND moneda = 'USD') AS saldo_cuentas_usd"
        );

        return $statement->fetch() ?: [];
    }

    public function associateStatuses(): array
    {
        return $this->rows(
            "SELECT estado, COUNT(*) AS total
               FROM per_asociados
              GROUP BY estado
              ORDER BY FIELD(estado, 'ACTIVO', 'PENDIENTE', 'SUSPENDIDO', 'INACTIVO', 'BAJA', 'FALLECIDO', 'RECHAZADO')"
        );
    }

    public function portfolioByProduct(): array
    {
        return $this->rows(
            "SELECT
                p.codigo,
                p.nombre,
                p.moneda,
                COUNT(DISTINCT a.id_ayuda) AS cantidad_ayudas,
                COALESCE(SUM(CASE WHEN c.estado = 'PENDIENTE' THEN c.importe_cuota ELSE 0 END), 0) AS saldo_pendiente
             FROM ae_productos p
             LEFT JOIN ae_ayudas a
               ON a.id_producto = p.id_producto
              AND a.estado = 'VIGENTE'
             LEFT JOIN ae_cuotas c ON c.id_ayuda = a.id_ayuda
             WHERE p.activo = 1
             GROUP BY p.id_producto, p.codigo, p.nombre, p.moneda
             ORDER BY p.id_producto"
        );
    }

    public function dueBuckets(): array
    {
        return $this->rows(
            "SELECT 'VENCIDAS' AS clave, 'Vencidas' AS nombre,
                    COUNT(*) AS cantidad,
                    COALESCE(SUM(CASE WHEN a.moneda = 'USD' THEN c.importe_cuota * COALESCE(a.cotizacion_dolar, 0) ELSE c.importe_cuota END), 0) AS importe
               FROM ae_cuotas c
               INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
              WHERE a.estado = 'VIGENTE'
                AND c.estado = 'PENDIENTE'
                AND c.fecha_vencimiento < CURDATE()
             UNION ALL
             SELECT 'HASTA_7', 'Próximos 7 días',
                    COUNT(*),
                    COALESCE(SUM(CASE WHEN a.moneda = 'USD' THEN c.importe_cuota * COALESCE(a.cotizacion_dolar, 0) ELSE c.importe_cuota END), 0)
               FROM ae_cuotas c
               INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
              WHERE a.estado = 'VIGENTE'
                AND c.estado = 'PENDIENTE'
                AND c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             UNION ALL
             SELECT 'DE_8_A_30', 'De 8 a 30 días',
                    COUNT(*),
                    COALESCE(SUM(CASE WHEN a.moneda = 'USD' THEN c.importe_cuota * COALESCE(a.cotizacion_dolar, 0) ELSE c.importe_cuota END), 0)
               FROM ae_cuotas c
               INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
              WHERE a.estado = 'VIGENTE'
                AND c.estado = 'PENDIENTE'
                AND c.fecha_vencimiento > DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                AND c.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             UNION ALL
             SELECT 'MAS_30', 'Más de 30 días',
                    COUNT(*),
                    COALESCE(SUM(CASE WHEN a.moneda = 'USD' THEN c.importe_cuota * COALESCE(a.cotizacion_dolar, 0) ELSE c.importe_cuota END), 0)
               FROM ae_cuotas c
               INNER JOIN ae_ayudas a ON a.id_ayuda = c.id_ayuda
              WHERE a.estado = 'VIGENTE'
                AND c.estado = 'PENDIENTE'
                AND c.fecha_vencimiento > DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        );
    }

    public function recentActivity(): array
    {
        return $this->rows(
            "SELECT id_evento, modulo, accion, entidad, id_entidad, resultado, creado_en
               FROM sis_auditoria_eventos
              WHERE modulo <> 'auth'
              ORDER BY id_evento DESC
              LIMIT 7"
        );
    }

    private function rows(string $sql): array
    {
        $statement = $this->db()->query($sql);
        return $statement->fetchAll();
    }
}
