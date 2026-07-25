<?php
declare(strict_types=1);

namespace App\Modules\Caja;

final class CajaService
{
    public function structure(): array
    {
        return [
            'modulo' => 'caja',
            'nombre' => 'Caja y tesorería',
            'estado' => 'estructura_inicial',
            'secciones' => ['Aperturas', 'Movimientos', 'Arqueos', 'Cierres'],
        ];
    }
}
