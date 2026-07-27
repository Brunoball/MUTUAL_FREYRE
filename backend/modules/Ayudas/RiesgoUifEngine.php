<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

/**
 * Motor explicable de segmentación preliminar LA/FT.
 *
 * No decide si se otorga una ayuda y no determina por sí mismo si una
 * operación es sospechosa. Aplica reglas configurables que deben ser
 * aprobadas por la Mutual y su Oficial de Cumplimiento antes de producción.
 */
final class RiesgoUifEngine
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $context
     * @param array<string,array<string,mixed>> $rules
     * @return array<string,mixed>
     */
    public function evaluate(array $input, array $context, array $rules): array
    {
        $alerts = [];
        $factors = [];
        $pendingDocuments = [];

        $identityVerified = (bool)($input['identidad_verificada'] ?? false);
        $documentationComplete = (bool)($input['documentacion_completa'] ?? false);
        $originDocumented = (bool)($input['origen_fondos_documentado'] ?? false);
        $terrorism = strtoupper((string)($input['terrorismo_resultado'] ?? 'PENDIENTE'));
        $declaredPep = strtoupper((string)($input['pep_estado'] ?? 'NO_INFORMA'));
        $internalPep = (bool)($context['persona_es_pep'] ?? false)
            || (int)($context['vinculos_pep'] ?? 0) > 0;
        $effectivePep = $declaredPep !== 'NO' && $declaredPep !== 'NO_INFORMA'
            ? $declaredPep
            : ($internalPep ? 'NACIONAL' : $declaredPep);

        if (!$identityVerified || !$documentationComplete) {
            $evidence = [
                'identidad_verificada' => $identityVerified,
                'documentacion_completa' => $documentationComplete,
            ];
            $this->addAlert($alerts, $rules, 'UIF-DOC-001', $evidence);
            if (!$identityVerified) {
                $pendingDocuments[] = 'Verificación de identidad y CUIT/CUIL/CDI';
            }
            if (!$documentationComplete) {
                $pendingDocuments[] = 'Documentación mínima del legajo';
            }
        } else {
            $factors[] = [
                'tipo' => 'MITIGANTE',
                'descripcion' => 'Identidad y documentación mínima verificadas.',
            ];
        }

        if (in_array($effectivePep, ['NACIONAL', 'EXTRANJERA'], true)) {
            $overrides = $effectivePep === 'EXTRANJERA'
                ? ['severidad' => 'ALTA', 'peso' => 5]
                : [];
            $this->addAlert($alerts, $rules, 'UIF-PEP-001', [
                'pep_estado' => $effectivePep,
                'origen' => $internalPep ? 'LEGAJO_INTERNO_Y_DECLARACION' : 'DECLARACION',
            ], $overrides);
            $pendingDocuments[] = 'Constancia y revisión PEP actualizada';
        }

        if (!$originDocumented) {
            $this->addAlert($alerts, $rules, 'UIF-ORI-001', [
                'origen_declarado' => trim((string)($input['origen_fondos'] ?? '')) !== '',
                'respaldo_presentado' => false,
            ]);
            $pendingDocuments[] = 'Respaldo del origen de fondos e ingresos';
        } else {
            $factors[] = [
                'tipo' => 'MITIGANTE',
                'descripcion' => 'Origen de fondos declarado y documentado.',
            ];
        }

        $amount = max(0.0, (float)($input['monto_solicitado'] ?? 0));
        $monthlyIncome = max(0.0, (float)($input['ingresos_mensuales'] ?? 0));
        $patrimony = max(0.0, (float)($input['patrimonio_estimado'] ?? 0));
        $capacityRule = $rules['UIF-CAP-001'] ?? [];
        $capacityConfig = is_array($capacityRule['configuracion'] ?? null)
            ? $capacityRule['configuracion']
            : [];
        $mediumMultiplier = max(
            1.0,
            (float)($capacityConfig['multiplicador_ingreso_medio'] ?? 12)
        );
        $highMultiplier = max(
            $mediumMultiplier,
            (float)($capacityConfig['multiplicador_ingreso_alto'] ?? 24)
        );

        if ($amount > 0 && $monthlyIncome > 0) {
            $ratio = $amount / $monthlyIncome;
            $patrimonyInsufficient = $patrimony > 0 && $amount > ($patrimony * 1.25);
            if ($ratio > $mediumMultiplier || $patrimonyInsufficient) {
                $overrides = $ratio > $highMultiplier
                    ? ['severidad' => 'ALTA', 'peso' => 5]
                    : [];
                $this->addAlert($alerts, $rules, 'UIF-CAP-001', [
                    'monto_solicitado' => round($amount, 2),
                    'ingresos_mensuales' => round($monthlyIncome, 2),
                    'patrimonio_estimado' => round($patrimony, 2),
                    'meses_de_ingreso_equivalentes' => round($ratio, 2),
                ], $overrides);
                $pendingDocuments[] = 'Justificación de capacidad económica';
            } else {
                $factors[] = [
                    'tipo' => 'MITIGANTE',
                    'descripcion' => 'Monto compatible con los ingresos informados.',
                ];
            }
        } elseif ($amount > 0) {
            $this->addAlert($alerts, $rules, 'UIF-CAP-001', [
                'monto_solicitado' => round($amount, 2),
                'ingresos_mensuales' => null,
                'motivo' => 'SIN_INGRESOS_INFORMADOS',
            ]);
            $pendingDocuments[] = 'Información de ingresos y capacidad económica';
        }

        if ((bool)($input['comportamiento_inusual'] ?? false)) {
            $this->addAlert($alerts, $rules, 'UIF-ANT-001', [
                'declarado_por_usuario' => true,
                'antecedentes_internos' => $context['antecedentes_resumen'] ?? [],
            ]);
        }

        if ($terrorism === 'COINCIDENCIA_POTENCIAL') {
            $this->addAlert($alerts, $rules, 'UIF-TER-001', [
                'resultado_repet' => 'COINCIDENCIA_POTENCIAL',
                'requiere_confirmacion_humana' => true,
                'control_repet' => $context['repet']['normalizado']['resumen'] ?? [],
                'hash_fuente' => $context['repet']['hash_sha256'] ?? null,
            ]);
        } elseif ($terrorism === 'PENDIENTE') {
            $pendingDocuments[] = 'Constancia de control RePET/listas aplicables';
        } else {
            $factors[] = [
                'tipo' => 'MITIGANTE',
                'descripcion' => 'Control RePET/listas registrado sin coincidencias.',
            ];
        }

        if ((bool)($input['datos_contradictorios'] ?? false)) {
            $this->addAlert($alerts, $rules, 'UIF-DAT-001', [
                'declarado_por_usuario' => true,
            ]);
        }

        if (
            (bool)($input['no_residente'] ?? false)
            || (bool)($input['jurisdiccion_riesgo'] ?? false)
        ) {
            $this->addAlert($alerts, $rules, 'UIF-GEO-001', [
                'no_residente' => (bool)($input['no_residente'] ?? false),
                'jurisdiccion_riesgo' => (bool)($input['jurisdiccion_riesgo'] ?? false),
            ]);
        }

        if ((bool)($input['efectivo_intensivo'] ?? false)) {
            $this->addAlert($alerts, $rules, 'UIF-EFE-001', [
                'actividad' => trim((string)($input['actividad'] ?? '')),
            ]);
        }

        if ((bool)($input['fondos_terceros'] ?? false)) {
            $this->addAlert($alerts, $rules, 'UIF-TERC-001', [
                'fondos_terceros' => true,
            ]);
            $pendingDocuments[] = 'Identificación y justificación de terceros intervinientes';
        }

        $score = 0;
        $hasCritical = false;
        foreach ($alerts as $alert) {
            $score += (int)($alert['peso'] ?? 0);
            $hasCritical = $hasCritical || $alert['severidad'] === 'CRITICA';
            $factors[] = [
                'tipo' => 'AGRAVANTE',
                'codigo' => $alert['codigo'],
                'descripcion' => $alert['descripcion'],
            ];
        }

        $level = $hasCritical || $score >= 7
            ? 'ALTO'
            : ($score >= 2 ? 'MEDIO' : 'BAJO');
        $measure = $hasCritical
            ? 'ESCALAR'
            : match ($level) {
                'ALTO' => 'REFORZADA',
                'MEDIO' => 'MEDIA',
                default => 'SIMPLIFICADA',
            };

        $versions = array_values(array_unique(array_filter(array_map(
            static fn (array $rule): string => (string)($rule['version_reglas'] ?? ''),
            $rules
        ))));

        return [
            'nivel_riesgo' => $level,
            'medida_requerida' => $measure,
            'version_reglas' => $versions[0] ?? 'UIF-99-2023-v1',
            'puntaje_interno' => $score,
            'tiene_alerta_critica' => $hasCritical,
            'alertas' => $alerts,
            'factores' => $factors,
            'documentacion_pendiente' => array_values(array_unique($pendingDocuments)),
            'advertencia' => 'Evaluación preliminar interna. No constituye una decisión de la UIF, un ROS automático ni una aprobación o rechazo crediticio.',
        ];
    }

    private function addAlert(
        array &$alerts,
        array $rules,
        string $code,
        array $evidence,
        array $overrides = []
    ): void {
        $rule = $rules[$code] ?? null;
        if (!is_array($rule) || !(bool)($rule['activa'] ?? true)) {
            return;
        }

        $alerts[] = [
            'codigo' => $code,
            'severidad' => (string)($overrides['severidad'] ?? $rule['severidad'] ?? 'MEDIA'),
            'peso' => (int)($overrides['peso'] ?? $rule['peso'] ?? 1),
            'descripcion' => (string)($rule['nombre'] ?? $code),
            'accion_requerida' => (string)($rule['accion_requerida'] ?? 'Revisar el caso.'),
            'evidencia' => $evidence,
        ];
    }
}
