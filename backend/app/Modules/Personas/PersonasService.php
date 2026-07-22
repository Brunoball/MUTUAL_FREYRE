<?php
declare(strict_types=1);

namespace App\Modules\Personas;

final class PersonasService
{
    public function structure(): array
    {
        return [
            'modulo' => 'personas',
            'nombre' => 'Personas y asociados',
            'estado' => 'estructura_inicial',
            'secciones' => ['Personas físicas y jurídicas', 'Asociados', 'Relaciones y autorizados', 'Cumplimiento documental'],
        ];
    }
}
