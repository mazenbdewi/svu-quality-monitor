<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ExecutiveDashboardWidget;
use App\Filament\Widgets\ResponseTimeTrendWidget;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.monitoring');
    }

    public function getWidgets(): array
    {
        return [ExecutiveDashboardWidget::class, ResponseTimeTrendWidget::class];
    }

    public function getTitle(): string|Htmlable
    {
        return __('monitoring.dashboard.title');
    }
}
