<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Modules\Ayudas\RepetScreeningClient;

final class RepetScreeningUnitTest
{
    private int $assertions = 0;

    public function run(): void
    {
        $this->testExactPersonAndEntityAlias();
        $this->testNoMatch();
        $this->testUnavailable();

        echo "OK - {$this->assertions} verificaciones del control RePET.\n";
    }

    private function client(bool $available = true): RepetScreeningClient
    {
        $persons = [[
            'DATAID' => 'P-1',
            'FIRST_NAME' => 'VALENTINA',
            'SECOND_NAME' => 'SANCHEZ',
            'REFERENCE_NUMBER' => 'AR-P-1',
            'UN_LIST_TYPE' => 'INDIVIDUAL',
            'LISTED_ON' => '2024-01-01',
            'INDIVIDUAL_ALIAS' => [],
        ]];
        $entities = [
            [
                'DATAID' => 'E-1',
                'FIRST_NAME' => 'EMPRESA INTERNACIONAL DEMO',
                'REFERENCE_NUMBER' => 'AR-E-1',
                'UN_LIST_TYPE' => 'ENTITY',
                'LISTED_ON' => '2024-02-01',
                'ENTITY_ALIAS' => [[
                    'ALIAS_NAME' => 'COMERCIAL DEL SUR; CDS',
                ]],
            ],
            [
                'DATAID' => 'E-2',
                'FIRST_NAME' => 'EMPRESA EJEMPLO S.A.',
                'REFERENCE_NUMBER' => 'AR-E-2',
                'UN_LIST_TYPE' => 'ENTITY',
                'LISTED_ON' => '2024-03-01',
                'ENTITY_ALIAS' => [],
            ],
        ];
        $transport = static function (string $key, string $url) use (
            $available,
            $persons,
            $entities
        ): array {
            if (!$available) {
                return [
                    'http_status' => 503,
                    'body' => '',
                    'duration_ms' => 5,
                    'error_code' => 'REMOTE_UNAVAILABLE',
                    'error_message' => 'Servicio no disponible.',
                ];
            }
            return [
                'http_status' => 200,
                'body' => json_encode(
                    $key === 'personas' ? $persons : $entities,
                    JSON_THROW_ON_ERROR
                ),
                'duration_ms' => 5,
                'error_code' => null,
                'error_message' => null,
            ];
        };

        return new RepetScreeningClient(null, $transport);
    }

    private function testExactPersonAndEntityAlias(): void
    {
        $source = $this->client()->screen([
            [
                'nombre' => 'SÁNCHEZ, VALENTINA',
                'rol' => 'PERSONA_CONSULTADA',
                'tipo' => 'PERSONA',
            ],
            [
                'nombre' => 'Comercial del Sur',
                'rol' => 'VINCULO_APODERADO',
                'tipo' => 'ENTIDAD',
            ],
            [
                'nombre' => 'Empresa Ejemplo Sociedad Anónima',
                'rol' => 'PERSONA_CONSULTADA',
                'tipo' => 'ENTIDAD',
            ],
        ]);

        $this->same('OK', $source['estado'], 'Usa listados oficiales válidos.');
        $this->same(
            'COINCIDENCIA_POTENCIAL',
            $source['normalizado']['resumen']['resultado'],
            'Una coincidencia se marca como potencial, no como identidad confirmada.'
        );
        $this->same(
            3,
            $source['normalizado']['resumen']['cantidad_coincidencias_potenciales'],
            'Controla persona, vínculo y variantes de forma jurídica.'
        );
        $this->same(
            true,
            $source['normalizado']['resumen']['requiere_revision_humana'],
            'Toda coincidencia requiere revisión humana.'
        );
        $this->same(
            64,
            strlen((string)$source['hash_sha256']),
            'Conserva hash de integridad.'
        );
    }

    private function testNoMatch(): void
    {
        $source = $this->client()->screen([[
            'nombre' => 'PERSONA SIN COINCIDENCIAS',
            'rol' => 'PERSONA_CONSULTADA',
            'tipo' => 'PERSONA',
        ]]);
        $this->same(
            'SIN_COINCIDENCIA',
            $source['normalizado']['resumen']['resultado'],
            'Distingue una consulta válida sin coincidencias.'
        );
        $this->same(
            0,
            $source['normalizado']['resumen']['cantidad_coincidencias_potenciales'],
            'No fabrica alertas.'
        );
    }

    private function testUnavailable(): void
    {
        $source = $this->client(false)->screen([[
            'nombre' => 'PERSONA DE PRUEBA',
            'tipo' => 'PERSONA',
        ]]);
        $this->same(
            'NO_DISPONIBLE',
            $source['estado'],
            'Una caída de RePET queda explícita y no se interpreta como resultado limpio.'
        );
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
}

(new RepetScreeningUnitTest())->run();
