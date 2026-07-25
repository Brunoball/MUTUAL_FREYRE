<?php
declare(strict_types=1);

namespace App\Modules\Bancos;

final class BancosPolicy
{
    public const VIEW = 'bancos.view';
    public const MANAGE = 'bancos.manage';
}
