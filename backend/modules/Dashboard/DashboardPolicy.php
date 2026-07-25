<?php
declare(strict_types=1);

namespace App\Modules\Dashboard;

final class DashboardPolicy
{
    public const VIEW = 'dashboard.view';
    public const MANAGE = 'dashboard.manage';
}
