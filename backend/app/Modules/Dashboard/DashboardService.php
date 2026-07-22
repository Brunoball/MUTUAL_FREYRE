<?php
declare(strict_types=1);

namespace App\Modules\Dashboard;

final class DashboardService
{
    public function structure(): array
    {
        return [
            'modulo' => 'dashboard',
            'nombre' => 'Inicio',
            'estado' => 'base_minima',
            'secciones' => [
                'Acceso de usuarios',
                'Sesiones seguras',
                'Bloqueo de intentos',
                'Auditoría de acceso',
                'Módulo inicial de socios',
            ],
        ];
    }
}
