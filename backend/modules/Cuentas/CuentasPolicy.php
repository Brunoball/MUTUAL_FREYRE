<?php
declare(strict_types=1);

namespace App\Modules\Cuentas;

final class CuentasPolicy
{
    public const VIEW = 'cuentas.view';
    public const MANAGE = 'cuentas.manage';
}
