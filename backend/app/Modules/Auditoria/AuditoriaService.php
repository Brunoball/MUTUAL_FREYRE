<?php
declare(strict_types=1);

namespace App\Modules\Auditoria;

final class AuditoriaService
{
    public function structure(): array
    {
        return [
            'modulo' => 'auditoria',
            'nombre' => 'Auditoría',
            'estado' => 'estructura_inicial',
            'secciones' => ['Accesos', 'Operaciones financieras', 'Permisos', 'Exportaciones'],
        ];
    }
}
