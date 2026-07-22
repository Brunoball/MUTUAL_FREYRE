<?php
declare(strict_types=1);

namespace App\Modules\Documentos;

final class DocumentosService
{
    public function structure(): array
    {
        return [
            'modulo' => 'documentos',
            'nombre' => 'Documentos y comprobantes',
            'estado' => 'estructura_inicial',
            'secciones' => ['Plantillas', 'Mutuos', 'Comprobantes', 'Storage privado'],
        ];
    }
}
