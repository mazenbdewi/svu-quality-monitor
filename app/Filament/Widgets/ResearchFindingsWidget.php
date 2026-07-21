<?php

namespace App\Filament\Widgets;

use App\Services\ResearchInterpretationService;
use Filament\Widgets\Widget;

class ResearchFindingsWidget extends Widget
{
    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.research-findings-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'findings' => app(ResearchInterpretationService::class)->dashboardFindings(),
        ];
    }
}
