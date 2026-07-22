<?php
declare(strict_types=1);

namespace App\Modules\Ayudas;

final class AyudasService
{
    public function structure(): array
    {
        return [
            'modulo' => 'ayudas',
            'nombre' => 'Ayudas económicas',
            'estado' => 'estructura_inicial',
            'secciones' => ['Solicitudes', 'Productos configurables', 'Liquidaciones y planes', 'Mutuos y renovaciones'],
        ];
    }
}
