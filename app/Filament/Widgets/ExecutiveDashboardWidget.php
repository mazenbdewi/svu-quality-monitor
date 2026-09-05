<?php

namespace App\Filament\Widgets;

use App\Services\ExecutiveDashboardService;
use Filament\Widgets\Widget;

class ExecutiveDashboardWidget extends Widget
{
    protected string $view = 'filament.widgets.executive-dashboard';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['dashboard' => app(ExecutiveDashboardService::class)->snapshot()];
    }
}
