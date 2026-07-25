<?php
declare(strict_types=1);

namespace App\Modules\Contabilidad;

final class ContabilidadService
{
    public function structure(): array
    {
        return [
            'modulo' => 'contabilidad',
            'nombre' => 'Contabilidad',
            'estado' => 'estructura_inicial',
            'secciones' => ['Plan de cuentas', 'Asientos', 'Períodos', 'Cierres'],
        ];
    }
}
