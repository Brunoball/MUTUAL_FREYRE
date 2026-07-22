<?php
declare(strict_types=1);

namespace App\Modules\Cuentas;

final class CuentasService
{
    public function structure(): array
    {
        return [
            'modulo' => 'cuentas',
            'nombre' => 'Cuentas de socios',
            'estado' => 'estructura_inicial',
            'secciones' => ['Cuenta común', 'Cuenta especial', 'Movimientos', 'Devengamientos'],
        ];
    }
}
