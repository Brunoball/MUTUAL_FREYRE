<?php
declare(strict_types=1);

namespace App\Modules\Valores;

final class ValoresService
{
    public function structure(): array
    {
        return [
            'modulo' => 'valores',
            'nombre' => 'Valores y cheques',
            'estado' => 'estructura_inicial',
            'secciones' => ['Cheques y eCheq', 'Endosos', 'Cartera y depósitos', 'Acreditaciones y rechazos'],
        ];
    }
}
