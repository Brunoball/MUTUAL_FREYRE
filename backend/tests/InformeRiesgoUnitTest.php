<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\ApiException;
use App\Modules\Ayudas\BcraCentralDeudoresClient;
use App\Modules\Ayudas\CuitValidator;
use App\Modules\Ayudas\RiesgoUifEngine;

final class InformeRiesgoUnitTest
{
    private int $assertions = 0;

    public function run(): void
    {
        $this->testCuit();
        $this->testBcraNormalization();
        $this->testBcraNoData();
        $this->testUifEngine();

        echo "OK - {$this->assertions} verificaciones del informe por CUIT.\n";
    }

    private function testCuit(): void
    {
        $this->same(
            '20301112220',
            CuitValidator::validate('20-30111222-0'),
            'Normaliza CUIT con separadores.'
        );
        $this->same(
            '20-*******-0',
            CuitValidator::mask('20301112220'),
            'Enmascara el CUIT para auditoría.'
        );

        $thrown = false;
        try {
            CuitValidator::validate('20-30111222-1');
        } catch (ApiException $error) {
            $thrown = $error->errorCode === 'INVALID_IDENTIFICATION_CHECK_DIGIT';
        }
        $this->true($thrown, 'Rechaza un dígito verificador incorrecto.');
    }

    private function testBcraNormalization(): void
    {
        $transport = static function (string $source, string $url): array {
            $results = match ($source) {
                BcraCentralDeudoresClient::SOURCE_CURRENT => [
                    'identificacion' => 20301112220,
                    'denominacion' => 'PERSONA DE PRUEBA',
                    'periodos' => [[
                        'periodo' => '202606',
                        'entidades' => [[
                            'entidad' => 'ENTIDAD A',
                            'situacion' => 2,
                            'monto' => 125.5,
                            'diasAtrasoPago' => 8,
                            'refinanciaciones' => false,
                            'recategorizacionOblig' => false,
                            'situacionJuridica' => false,
                            'irrecDisposicionTecnica' => false,
                            'enRevision' => false,
                            'procesoJud' => false,
                        ]],
                    ]],
                ],
                BcraCentralDeudoresClient::SOURCE_HISTORY => [
                    'identificacion' => 20301112220,
                    'denominacion' => 'PERSONA DE PRUEBA',
                    'periodos' => [[
                        'periodo' => '202605',
                        'entidades' => [[
                            'entidad' => 'ENTIDAD A',
                            'situacion' => 3,
                            'monto' => 100,
                            'enRevision' => false,
                            'procesoJud' => false,
                        ]],
                    ]],
                ],
                BcraCentralDeudoresClient::SOURCE_CHEQUES => [
                    'identificacion' => 20301112220,
                    'denominacion' => 'PERSONA DE PRUEBA',
                    'causales' => [[
                        'causal' => 'SIN FONDOS',
                        'entidades' => [[
                            'entidad' => 1,
                            'detalle' => [[
                                'nroCheque' => 123,
                                'fechaRechazo' => '2026-06-15',
                                'monto' => 30000,
                                'fechaPago' => null,
                                'fechaPagoMulta' => null,
                                'estadoMulta' => null,
                                'ctaPersonal' => true,
                                'denomJuridica' => null,
                                'enRevision' => false,
                                'procesoJud' => false,
                            ]],
                        ]],
                    ]],
                ],
            };
            return [
                'http_status' => 200,
                'body' => json_encode(
                    ['status' => 200, 'results' => $results],
                    JSON_THROW_ON_ERROR
                ),
                'duration_ms' => 18,
                'error_code' => null,
                'error_message' => null,
            ];
        };

        $sources = (new BcraCentralDeudoresClient(null, $transport))
            ->queryAll('20301112220');
        $current = $sources[BcraCentralDeudoresClient::SOURCE_CURRENT];
        $history = $sources[BcraCentralDeudoresClient::SOURCE_HISTORY];
        $checks = $sources[BcraCentralDeudoresClient::SOURCE_CHEQUES];

        $this->same('OK', $current['estado'], 'Normaliza deuda actual.');
        $this->same(
            125500.0,
            $current['normalizado']['resumen']['deuda_total_pesos_estimada'],
            'Respeta que el monto BCRA se informa en miles de pesos.'
        );
        $this->same(
            3,
            $history['normalizado']['resumen']['peor_situacion_24_meses'],
            'Calcula peor situación histórica.'
        );
        $this->same(
            1,
            $checks['normalizado']['resumen']['cantidad_pendientes_pago'],
            'Detecta cheque rechazado sin fecha de pago.'
        );
        $this->same(
            64,
            strlen((string)$current['hash_sha256']),
            'Genera hash SHA-256 de la respuesta oficial.'
        );
    }

    private function testBcraNoData(): void
    {
        $transport = static fn (string $source, string $url): array => [
            'http_status' => 404,
            'body' => json_encode(
                ['status' => 404, 'errorMessages' => ['Sin información']],
                JSON_THROW_ON_ERROR
            ),
            'duration_ms' => 10,
            'error_code' => null,
            'error_message' => null,
        ];
        $sources = (new BcraCentralDeudoresClient(null, $transport))
            ->queryAll('20301112220');
        foreach ($sources as $source) {
            $this->same(
                'SIN_DATOS',
                $source['estado'],
                'HTTP 404 BCRA se interpreta como ausencia de datos, no como falla.'
            );
        }
    }

    private function testUifEngine(): void
    {
        $rules = $this->rules();
        $base = [
            'actividad' => 'Servicios técnicos',
            'proposito' => 'Capital de trabajo',
            'origen_fondos' => 'Ingresos de actividad declarada',
            'monto_solicitado' => 1_000_000,
            'ingresos_mensuales' => 500_000,
            'patrimonio_estimado' => 10_000_000,
            'identidad_verificada' => true,
            'documentacion_completa' => true,
            'origen_fondos_documentado' => true,
            'pep_estado' => 'NO',
            'terrorismo_resultado' => 'SIN_COINCIDENCIA',
            'no_residente' => false,
            'jurisdiccion_riesgo' => false,
            'efectivo_intensivo' => false,
            'fondos_terceros' => false,
            'datos_contradictorios' => false,
            'comportamiento_inusual' => false,
        ];
        $context = [
            'persona_es_pep' => false,
            'vinculos_pep' => 0,
            'antecedentes_resumen' => [],
        ];
        $engine = new RiesgoUifEngine();

        $low = $engine->evaluate($base, $context, $rules);
        $this->same('BAJO', $low['nivel_riesgo'], 'Segmenta un perfil documentado como bajo.');
        $this->same(0, count($low['alertas']), 'No crea alertas sin disparadores.');

        $pep = $engine->evaluate(
            array_merge($base, ['pep_estado' => 'NACIONAL']),
            $context,
            $rules
        );
        $this->same('MEDIO', $pep['nivel_riesgo'], 'PEP activa debida diligencia reforzada.');
        $this->same('UIF-PEP-001', $pep['alertas'][0]['codigo'], 'La alerta UIF es explicable.');

        $critical = $engine->evaluate(
            array_merge($base, ['terrorismo_resultado' => 'COINCIDENCIA_POTENCIAL']),
            $context,
            $rules
        );
        $this->same('ALTO', $critical['nivel_riesgo'], 'Coincidencia potencial escala el riesgo.');
        $this->true($critical['tiene_alerta_critica'], 'Marca la intervención de Cumplimiento.');
        $this->same('ESCALAR', $critical['medida_requerida'], 'No emite un rechazo automático.');
    }

    private function rules(): array
    {
        $definitions = [
            'UIF-DOC-001' => ['ALTA', 4],
            'UIF-PEP-001' => ['MEDIA', 3],
            'UIF-ORI-001' => ['ALTA', 4],
            'UIF-CAP-001' => ['MEDIA', 3],
            'UIF-ANT-001' => ['MEDIA', 2],
            'UIF-TER-001' => ['CRITICA', 10],
            'UIF-DAT-001' => ['ALTA', 5],
            'UIF-GEO-001' => ['ALTA', 4],
            'UIF-EFE-001' => ['MEDIA', 2],
            'UIF-TERC-001' => ['ALTA', 4],
        ];
        $rules = [];
        foreach ($definitions as $code => [$severity, $weight]) {
            $rules[$code] = [
                'codigo' => $code,
                'nombre' => 'Regla de prueba ' . $code,
                'severidad' => $severity,
                'peso' => $weight,
                'accion_requerida' => 'Revisar y documentar.',
                'configuracion' => $code === 'UIF-CAP-001'
                    ? [
                        'multiplicador_ingreso_medio' => 12,
                        'multiplicador_ingreso_alto' => 24,
                    ]
                    : [],
                'version_reglas' => 'UIF-99-2023-v1',
                'activa' => true,
            ];
        }
        return $rules;
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . ' Esperado: ' . var_export($expected, true)
                . '. Obtenido: ' . var_export($actual, true)
            );
        }
    }

    private function true(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

(new InformeRiesgoUnitTest())->run();

