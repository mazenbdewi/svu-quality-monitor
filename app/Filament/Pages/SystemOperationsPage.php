<?php

namespace App\Filament\Pages;

use App\Services\SystemHealthService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SystemOperationsPage extends Page
{
    /** @var array<string, array<string, mixed>> */
    public array $health = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $slug = 'system-operations';

    protected static ?int $navigationSort = 32;

    protected string $view = 'filament.pages.system-operations-page';

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    public static function getNavigationLabel(): string
    {
        return __('monitoring.operations.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.study_methodology');
    }

    public function getTitle(): string
    {
        return __('monitoring.operations.title');
    }

    public function mount(SystemHealthService $systemHealth): void
    {
        $this->health = $systemHealth->snapshot();
    }
}
