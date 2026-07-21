<?php

namespace App\Filament\Pages;

use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.monitoring');
    }

    public function getTitle(): string|Htmlable
    {
        return __('monitoring.dashboard.title');
    }
}
