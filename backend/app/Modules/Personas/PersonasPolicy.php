<?php
declare(strict_types=1);

namespace App\Modules\Personas;

final class PersonasPolicy
{
    public const VIEW = 'personas.view';
    public const MANAGE = 'personas.manage';
}
