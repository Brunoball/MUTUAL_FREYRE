<?php
declare(strict_types=1);

namespace App\Modules\Ahorros;

final class AhorrosService
{
    public function structure(): array
    {
        return [
            'modulo' => 'ahorros',
            'nombre' => 'Ahorros a término',
            'estado' => 'estructura_inicial',
            'secciones' => ['Pesos', 'Dólares', 'Tasas y plazos', 'Vencimientos y renovaciones'],
        ];
    }
}
