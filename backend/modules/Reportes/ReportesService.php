<?php
declare(strict_types=1);

namespace App\Modules\Reportes;

final class ReportesService
{
    public function structure(): array
    {
        return [
            'modulo' => 'reportes',
            'nombre' => 'Reportes y exportaciones',
            'estado' => 'estructura_inicial',
            'secciones' => ['Operativos', 'Financieros', 'Regulatorios', 'Archivos generados'],
        ];
    }
}
