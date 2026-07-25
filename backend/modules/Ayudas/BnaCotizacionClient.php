<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Core\ApiException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Obtiene una referencia de cotización para que el operador la revise.
 *
 * Prioridad:
 *  1) Sitio oficial del Banco Nación (páginas livianas AMP y pantalla histórica).
 *  2) DolarApi como respaldo referencial cuando BNA no responde.
 *
 * Nunca guarda la cotización: solo devuelve compra, venta y promedio sugerido.
 */
final class BnaCotizacionClient
{
    private const BNA_PERSONAS_AMP = 'https://www.bna.com.ar/Personas?view=amp';
    private const BNA_EMPRESAS_AMP = 'https://www.bna.com.ar/Empresas?view=amp';
    private const BNA_HISTORY = 'https://www.bna.com.ar/Cotizador/HistoricoPrincipales';
    private const FALLBACK_URL = 'https://dolarapi.com/v1/dolares/oficial';
    private const FALLBACK_HISTORY_URL = 'https://api.argentinadatos.com/v1/cotizaciones/dolares/oficial';
    private const MAX_RESPONSE_BYTES = 6_000_000;

    public function currentUsdBillete(): array
    {
        foreach ($this->officialUrls() as $url) {
            try {
                $quote = $this->parseBnaHtml($this->download($url));
                return $this->response($quote, $url, false);
            } catch (\Throwable) {
                // Se intenta automáticamente con la siguiente fuente oficial.
            }
        }

        foreach ([self::FALLBACK_URL, self::FALLBACK_HISTORY_URL] as $url) {
            try {
                $quote = $this->parseFallbackJson($this->download($url));
                return $this->response($quote, $url, true);
            } catch (\Throwable) {
                // Se intenta el siguiente servicio de respaldo.
            }
        }

        throw $this->unavailable();
    }

    /** @return list<string> */
    private function officialUrls(): array
    {
        $today = new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $historyQuery = http_build_query([
            'fecha' => $today->format('d/m/Y'),
            'filtroDolar' => '1',
            'id' => 'billetes',
        ]);

        return [
            self::BNA_PERSONAS_AMP,
            self::BNA_EMPRESAS_AMP,
            self::BNA_HISTORY . '?' . $historyQuery,
        ];
    }

    private function response(array $quote, string $sourceUrl, bool $fallback): array
    {
        $purchase = round((float)$quote['compra'], 6);
        $sale = round((float)$quote['venta'], 6);

        return [
            'moneda' => 'DOLAR U.S.A.',
            'tipo_cotizacion' => $fallback ? 'OFICIAL_REFERENCIAL' : 'BILLETE',
            'compra' => $purchase,
            'venta' => $sale,
            'promedio' => round(($purchase + $sale) / 2, 6),
            'fecha_cotizacion' => $quote['fecha_cotizacion'] ?? null,
            'hora_actualizacion' => $quote['hora_actualizacion'] ?? null,
            'consultado_en' => date(DATE_ATOM),
            'es_respaldo' => $fallback,
            'fuente' => $fallback
                ? 'DÓLAR OFICIAL - REFERENCIA AUTOMÁTICA DE RESPALDO'
                : 'BANCO NACIÓN - COTIZACIÓN BILLETE (PROMEDIO COMPRA/VENTA)',
            'url_fuente' => $sourceUrl,
        ];
    }

    private function download(string $url): string
    {
        if (function_exists('curl_init')) {
            $body = $this->downloadWithCurl($url);
            if ($body !== null) {
                return $body;
            }
            throw $this->unavailable();
        }

        $body = $this->downloadWithStreams($url);
        if ($body !== null) {
            return $body;
        }

        throw $this->unavailable();
    }

    private function downloadWithCurl(string $url): ?string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language: es-AR,es;q=0.9,en;q=0.7',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
        ];

        if (defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        // En Windows permite usar el almacén nativo de certificados y evita
        // el error típico de PHP/cURL sin un cacert.pem configurado.
        if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }

        $configuredCa = trim((string)ini_get('curl.cainfo'));
        if ($configuredCa !== '' && is_file($configuredCa)) {
            $options[CURLOPT_CAINFO] = $configuredCa;
        }

        curl_setopt_array($curl, $options);
        $result = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (
            is_string($result)
            && $result !== ''
            && strlen($result) <= self::MAX_RESPONSE_BYTES
            && $status >= 200
            && $status < 300
        ) {
            return $result;
        }

        return null;
    }

    private function downloadWithStreams(string $url): ?string
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 4,
                'header' => implode("\r\n", [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0.0.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                    'Accept-Language: es-AR,es;q=0.9,en;q=0.7',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache',
                    'Connection: close',
                ]),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES + 1);
        $status = $this->streamStatus($http_response_header ?? []);

        if (
            !is_string($body)
            || $body === ''
            || strlen($body) > self::MAX_RESPONSE_BYTES
            || $status < 200
            || $status >= 300
        ) {
            return null;
        }

        return $body;
    }

    private function streamStatus(array $headers): int
    {
        foreach (array_reverse($headers) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string)$header, $match) === 1) {
                return (int)$match[1];
            }
        }
        return 0;
    }

    private function parseBnaHtml(string $html): array
    {
        $structured = $this->parseBnaRows($html);
        if ($structured !== null) {
            return $structured;
        }

        return $this->parseBnaText($html);
    }

    private function parseBnaRows(string $html): ?array
    {
        if (preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $html, $rows, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        foreach ($rows[1] as $rowMatch) {
            $rowHtml = (string)$rowMatch[0];
            $rowOffset = (int)$rowMatch[1];

            if (preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $cellMatches) < 3) {
                continue;
            }

            $cells = array_map(fn(string $cell): string => $this->cleanHtmlText($cell), $cellMatches[1]);
            $dollarIndex = null;
            foreach ($cells as $index => $cell) {
                if ($this->isUsdLabel($cell)) {
                    $dollarIndex = $index;
                    break;
                }
            }
            if ($dollarIndex === null) {
                continue;
            }

            $numbers = [];
            for ($index = $dollarIndex + 1, $count = count($cells); $index < $count; $index++) {
                if ($this->looksLikeDate($cells[$index])) {
                    continue;
                }
                $number = $this->argentineNumber($cells[$index]);
                if ($number > 0) {
                    $numbers[] = $number;
                }
                if (count($numbers) === 2) {
                    break;
                }
            }

            if (count($numbers) !== 2 || !$this->validQuote($numbers[0], $numbers[1])) {
                continue;
            }

            $rowDate = null;
            foreach ($cells as $cell) {
                $rowDate = $this->extractDate($cell);
                if ($rowDate !== null) {
                    break;
                }
            }

            $contextStart = max(0, $rowOffset - 1800);
            $context = substr($html, $contextStart, 3600);

            return [
                'compra' => round($numbers[0], 6),
                'venta' => round($numbers[1], 6),
                'fecha_cotizacion' => $rowDate ?? $this->extractNearestDate($context),
                'hora_actualizacion' => $this->extractTime($context),
            ];
        }

        return null;
    }

    private function parseBnaText(string $html): array
    {
        $text = $this->cleanHtmlText($html);
        $headingPosition = $this->firstPosition($text, ['Cotización Billetes', 'Cotizacion Billetes']);
        if ($headingPosition === null) {
            $headingPosition = 0;
        }

        if (preg_match(
            '/D[oó]lar\s+U\.?\s*S\.?\s*A\.?\s+\$?\s*([0-9][0-9.,]*)\s+\$?\s*([0-9][0-9.,]*)/iu',
            $text,
            $values,
            PREG_OFFSET_CAPTURE,
            $headingPosition
        ) !== 1) {
            throw $this->unavailable();
        }

        $purchase = $this->argentineNumber($values[1][0]);
        $sale = $this->argentineNumber($values[2][0]);
        if (!$this->validQuote($purchase, $sale)) {
            throw $this->unavailable();
        }

        $dollarPosition = (int)$values[0][1];
        $context = substr($text, max(0, $dollarPosition - 1200), 3000);

        return [
            'compra' => round($purchase, 6),
            'venta' => round($sale, 6),
            'fecha_cotizacion' => $this->extractNearestDate($context),
            'hora_actualizacion' => $this->extractTime($context),
        ];
    }

    private function parseFallbackJson(string $json): array
    {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw $this->unavailable();
        }

        if (array_is_list($decoded)) {
            $decoded = $this->latestFallbackRow($decoded);
        }

        $purchase = (float)($decoded['compra'] ?? 0);
        $sale = (float)($decoded['venta'] ?? 0);
        if (!$this->validQuote($purchase, $sale)) {
            throw $this->unavailable();
        }

        $date = null;
        $time = null;
        $updatedAt = trim((string)($decoded['fechaActualizacion'] ?? $decoded['fecha'] ?? ''));
        if ($updatedAt !== '') {
            try {
                $parsed = new DateTimeImmutable($updatedAt);
                $parsed = $parsed->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
                $date = $parsed->format('Y-m-d');
                $time = $parsed->format('H:i');
            } catch (\Throwable) {
                $date = null;
                $time = null;
            }
        }

        return [
            'compra' => round($purchase, 6),
            'venta' => round($sale, 6),
            'fecha_cotizacion' => $date,
            'hora_actualizacion' => $time,
        ];
    }


    private function latestFallbackRow(array $rows): array
    {
        $validRows = array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)));
        if ($validRows === []) {
            throw $this->unavailable();
        }

        usort($validRows, static function (array $left, array $right): int {
            return strcmp((string)($right['fecha'] ?? ''), (string)($left['fecha'] ?? ''));
        });

        return $validRows[0];
    }

    private function cleanHtmlText(string $value): string
    {
        $withoutScripts = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $value) ?? $value;
        $text = preg_replace('/<[^>]+>/', ' ', $withoutScripts) ?? $withoutScripts;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function isUsdLabel(string $value): bool
    {
        $normalized = $this->normalizeLabel($value);
        return str_contains($normalized, 'dolar u.s.a') || str_contains($normalized, 'dolar usa');
    }

    private function normalizeLabel(string $value): string
    {
        $value = strtolower(trim($value));
        return strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
    }

    private function looksLikeDate(string $value): bool
    {
        return preg_match('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}\b/', $value) === 1;
    }

    private function extractDate(string $value): ?string
    {
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/', $value, $match) !== 1) {
            return null;
        }

        $day = (int)$match[1];
        $month = (int)$match[2];
        $year = (int)$match[3];
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function extractNearestDate(string $context): ?string
    {
        $text = $this->cleanHtmlText($context);
        if (preg_match_all('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}\b/', $text, $matches) < 1) {
            return null;
        }

        foreach (array_reverse($matches[0]) as $value) {
            $date = $this->extractDate((string)$value);
            if ($date !== null) {
                return $date;
            }
        }
        return null;
    }

    private function extractTime(string $context): ?string
    {
        $text = $this->cleanHtmlText($context);
        if (preg_match('/Hora\s+Actualizaci[oó]n\s*:?\s*(\d{1,2}:\d{2})/iu', $text, $match) === 1) {
            return $match[1];
        }
        return null;
    }

    private function firstPosition(string $text, array $needles): ?int
    {
        foreach ($needles as $needle) {
            $position = stripos($text, $needle);
            if ($position !== false) {
                return $position;
            }
        }
        return null;
    }

    private function argentineNumber(string $value): float
    {
        $normalized = preg_replace('/[^0-9.,-]/', '', trim($value)) ?? '';
        if ($normalized === '') {
            return 0.0;
        }

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (substr_count($normalized, '.') > 1) {
            $normalized = str_replace('.', '', $normalized);
        }

        return is_numeric($normalized) ? (float)$normalized : 0.0;
    }

    private function validQuote(float $purchase, float $sale): bool
    {
        return $purchase > 0
            && $sale > 0
            && $sale >= $purchase
            && $sale <= 100_000;
    }

    private function unavailable(): ApiException
    {
        return new ApiException(
            'No se pudo obtener una cotización automática en este momento. Podés completar el valor manualmente y volver a intentarlo más tarde.',
            'BNA_QUOTE_UNAVAILABLE',
            503
        );
    }
}
