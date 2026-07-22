<?php
declare(strict_types=1);

namespace App\Modules\Cobranzas;

final class CobranzasService
{
    public function structure(): array
    {
        return [
            'modulo' => 'cobranzas',
            'nombre' => 'Cobranzas y mora',
            'estado' => 'estructura_inicial',
            'secciones' => ['Cuotas y componentes', 'Imputaciones', 'Mora', 'Anulaciones y convenios'],
        ];
    }
}
