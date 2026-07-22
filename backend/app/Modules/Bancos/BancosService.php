<?php
declare(strict_types=1);

namespace App\Modules\Bancos;

final class BancosService
{
    public function structure(): array
    {
        return [
            'modulo' => 'bancos',
            'nombre' => 'Bancos y conciliaciones',
            'estado' => 'estructura_inicial',
            'secciones' => ['Cuentas bancarias', 'Transferencias', 'Extractos', 'Conciliaciones'],
        ];
    }
}
