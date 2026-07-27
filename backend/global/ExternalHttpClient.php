<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cliente HTTPS acotado para integraciones públicas.
 *
 * - No sigue redirecciones.
 * - Valida TLS.
 * - Limita tiempo y tamaño de respuesta.
 * - Exige una lista explícita de hosts permitidos para reducir riesgo SSRF.
 */
final class ExternalHttpClient
{
    public function getJson(
        string $url,
        array $allowedHosts,
        array $headers = [],
        int $timeoutSeconds = 7,
        int $maximumBytes = 3_000_000
    ): array {
        $responses = $this->getManyJson(
            ['request' => $url],
            $allowedHosts,
            $headers,
            $timeoutSeconds,
            $maximumBytes
        );

        return $responses['request'];
    }

    /**
     * @param array<string,string> $urls
     * @return array<string,array{
     *   http_status:int,
     *   body:string,
     *   duration_ms:int,
     *   error_code:?string,
     *   error_message:?string
     * }>
     */
    public function getManyJson(
        array $urls,
        array $allowedHosts,
        array $headers = [],
        int $timeoutSeconds = 7,
        int $maximumBytes = 3_000_000
    ): array {
        $results = [];
        $validUrls = [];

        foreach ($urls as $key => $url) {
            $validationError = $this->validateUrl((string)$url, $allowedHosts);
            if ($validationError !== null) {
                $results[(string)$key] = $this->errorResult(
                    'EXTERNAL_URL_NOT_ALLOWED',
                    $validationError
                );
                continue;
            }
            $validUrls[(string)$key] = (string)$url;
        }

        if ($validUrls === []) {
            return $results;
        }

        if (!function_exists('curl_multi_init')) {
            foreach ($validUrls as $key => $_url) {
                $results[$key] = $this->errorResult(
                    'CURL_EXTENSION_MISSING',
                    'La extensión cURL no está disponible en el servidor.'
                );
            }
            return $results;
        }

        $multi = curl_multi_init();
        if ($multi === false) {
            foreach ($validUrls as $key => $_url) {
                $results[$key] = $this->errorResult(
                    'CURL_INITIALIZATION_FAILED',
                    'No se pudo inicializar el cliente HTTP.'
                );
            }
            return $results;
        }

        $handles = [];
        $buffers = [];
        $tooLarge = [];

        foreach ($validUrls as $key => $url) {
            $handle = curl_init();
            if ($handle === false) {
                $results[$key] = $this->errorResult(
                    'CURL_INITIALIZATION_FAILED',
                    'No se pudo inicializar la solicitud HTTP.'
                );
                continue;
            }

            $buffers[$key] = '';
            $tooLarge[$key] = false;
            $requestHeaders = array_values(array_merge([
                'Accept: application/json',
                'Cache-Control: no-cache',
            ], $headers));

            curl_setopt_array($handle, [
                CURLOPT_URL => $url,
                CURLOPT_HTTPGET => true,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => min(3_000, $timeoutSeconds * 1000),
                CURLOPT_TIMEOUT_MS => $timeoutSeconds * 1000,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_USERAGENT => (string)Env::get(
                    'EXTERNAL_HTTP_USER_AGENT',
                    'MutualFreyre/1.0 (+https://mutual9defreyre.3devsnet.com)'
                ),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_NOSIGNAL => true,
                CURLOPT_WRITEFUNCTION => static function (
                    mixed $_handle,
                    string $chunk
                ) use (&$buffers, &$tooLarge, $key, $maximumBytes): int {
                    if (strlen($buffers[$key]) + strlen($chunk) > $maximumBytes) {
                        $tooLarge[$key] = true;
                        return 0;
                    }
                    $buffers[$key] .= $chunk;
                    return strlen($chunk);
                },
            ]);

            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            }

            curl_multi_add_handle($multi, $handle);
            $handles[$key] = $handle;
        }

        try {
            do {
                $status = curl_multi_exec($multi, $running);
                if ($status !== CURLM_OK) {
                    break;
                }
                if ($running > 0) {
                    $selected = curl_multi_select($multi, 0.25);
                    if ($selected === -1) {
                        usleep(10_000);
                    }
                }
            } while ($running > 0);

            foreach ($handles as $key => $handle) {
                $curlError = curl_error($handle);
                $curlCode = curl_errno($handle);
                $durationMs = defined('CURLINFO_TOTAL_TIME_T')
                    ? max(0, (int)round(
                        (int)curl_getinfo($handle, CURLINFO_TOTAL_TIME_T) / 1000
                    ))
                    : max(0, (int)round(
                        (float)curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000
                    ));
                $httpStatus = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

                if ($tooLarge[$key]) {
                    $results[$key] = [
                        'http_status' => $httpStatus,
                        'body' => '',
                        'duration_ms' => $durationMs,
                        'error_code' => 'EXTERNAL_RESPONSE_TOO_LARGE',
                        'error_message' => 'La fuente externa superó el tamaño máximo permitido.',
                    ];
                } elseif ($curlCode !== CURLE_OK) {
                    $results[$key] = [
                        'http_status' => $httpStatus,
                        'body' => '',
                        'duration_ms' => $durationMs,
                        'error_code' => 'EXTERNAL_NETWORK_ERROR',
                        'error_message' => $curlError !== ''
                            ? mb_substr($curlError, 0, 300)
                            : 'No se pudo conectar con la fuente externa.',
                    ];
                } else {
                    $results[$key] = [
                        'http_status' => $httpStatus,
                        'body' => $buffers[$key],
                        'duration_ms' => $durationMs,
                        'error_code' => null,
                        'error_message' => null,
                    ];
                }
            }
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }

        return $results;
    }

    private function validateUrl(string $url, array $allowedHosts): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return 'La integración externa requiere una URL HTTPS válida.';
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $normalizedAllowed = array_map(
            static fn (mixed $value): string => strtolower(trim((string)$value)),
            $allowedHosts
        );
        if ($host === '' || !in_array($host, $normalizedAllowed, true)) {
            return 'El host solicitado no pertenece a las fuentes externas autorizadas.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'No se permiten credenciales embebidas en la URL externa.';
        }

        return null;
    }

    private function errorResult(string $code, string $message): array
    {
        return [
            'http_status' => 0,
            'body' => '',
            'duration_ms' => 0,
            'error_code' => $code,
            'error_message' => $message,
        ];
    }
}
