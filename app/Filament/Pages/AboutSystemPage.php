<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AboutSystemPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected static ?string $slug = 'about-system';

    protected static ?int $navigationSort = 31;

    protected string $view = 'filament.pages.about-system-page';

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    public static function getNavigationLabel(): string
    {
        return __('monitoring.about.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.study_methodology');
    }

    public function getTitle(): string
    {
        return __('monitoring.about.title');
    }
}
