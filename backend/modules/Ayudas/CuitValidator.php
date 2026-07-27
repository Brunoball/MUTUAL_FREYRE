<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

use App\Core\ApiException;

final class CuitValidator
{
    public static function validate(mixed $value, string $field = 'identificacion'): string
    {
        $normalized = preg_replace('/\D+/', '', trim((string)$value)) ?? '';
        if (!preg_match('/^\d{11}$/', $normalized)) {
            throw new ApiException(
                'Ingresá un CUIT/CUIL/CDI de 11 dígitos.',
                'INVALID_IDENTIFICATION',
                422,
                [$field => 'Debe contener exactamente 11 dígitos.']
            );
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += ((int)$normalized[$index]) * $weight;
        }

        $digit = 11 - ($sum % 11);
        if ($digit === 11) {
            $digit = 0;
        } elseif ($digit === 10) {
            $digit = 9;
        }

        if ($digit !== (int)$normalized[10]) {
            throw new ApiException(
                'El dígito verificador del CUIT/CUIL/CDI no es válido.',
                'INVALID_IDENTIFICATION_CHECK_DIGIT',
                422,
                [$field => 'El dígito verificador no coincide.']
            );
        }

        return $normalized;
    }

    public static function mask(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 11) {
            return '***********';
        }
        return substr($digits, 0, 2) . '-*******-' . substr($digits, -1);
    }
}

