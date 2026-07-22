<?php
declare(strict_types=1);

namespace App\Modules\Configuracion;

final class ConfiguracionService
{
    public function structure(): array
    {
        return [
            'modulo' => 'configuracion',
            'nombre' => 'Configuración',
            'estado' => 'estructura_inicial',
            'secciones' => ['Usuarios y permisos', 'Sucursales y cajas', 'Productos y tasas', 'Datos institucionales'],
        ];
    }
}
