<?php
declare(strict_types=1);

namespace App\Modules\Dashboard;

final class DashboardService
{
    public function __construct(private readonly DashboardRepository $repository) {}

    public function overview(): array
    {
        $summary = $this->repository->summary();

        return [
            'generado_en' => date(DATE_ATOM),
            'resumen' => $this->normalizeSummary($summary),
            'estados_asociados' => array_map(
                static fn(array $item): array => [
                    'estado' => (string)$item['estado'],
                    'total' => (int)$item['total'],
                ],
                $this->repository->associateStatuses()
            ),
            'cartera_por_producto' => array_map(
                static fn(array $item): array => [
                    'codigo' => (string)$item['codigo'],
                    'nombre' => (string)$item['nombre'],
                    'moneda' => (string)$item['moneda'],
                    'cantidad_ayudas' => (int)$item['cantidad_ayudas'],
                    'saldo_pendiente' => (float)$item['saldo_pendiente'],
                ],
                $this->repository->portfolioByProduct()
            ),
            'vencimientos' => array_map(
                static fn(array $item): array => [
                    'clave' => (string)$item['clave'],
                    'nombre' => (string)$item['nombre'],
                    'cantidad' => (int)$item['cantidad'],
                    'importe' => (float)$item['importe'],
                ],
                $this->repository->dueBuckets()
            ),
            'actividad_reciente' => $this->repository->recentActivity(),
        ];
    }

    private function normalizeSummary(array $summary): array
    {
        $integerKeys = [
            'personas_activas',
            'personas_fisicas',
            'personas_juridicas',
            'asociados_activos',
            'asociados_totales',
            'vinculos_activos',
            'ayudas_vigentes',
            'cuotas_vencidas',
            'cuotas_proximas_7',
            'cuotas_proximas_30',
            'cuentas_ahorro_activas',
        ];
        $moneyKeys = [
            'capital_vigente_ars',
            'cartera_pendiente_ars',
            'importe_vencido_ars',
            'importe_proximo_7_ars',
            'importe_proximo_30_ars',
            'saldo_cuentas_ars',
            'saldo_cuentas_usd',
        ];

        $normalized = [];
        foreach ($integerKeys as $key) {
            $normalized[$key] = (int)($summary[$key] ?? 0);
        }
        foreach ($moneyKeys as $key) {
            $normalized[$key] = (float)($summary[$key] ?? 0);
        }

        return $normalized;
    }
}
