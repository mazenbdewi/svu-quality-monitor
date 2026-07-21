<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AnalysisMethodologyPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $slug = 'analysis-methodology';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.analysis-methodology-page';

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    public static function getNavigationLabel(): string
    {
        return __('monitoring.methodology.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.study_methodology');
    }

    public function getTitle(): string
    {
        return __('monitoring.methodology.title');
    }
}
