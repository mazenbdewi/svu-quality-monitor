<?php

namespace App\Filament\Pages;

use App\Services\SystemDiagnosticsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use UnitEnum;

class SystemDiagnosticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $slug = 'system-diagnostics';

    protected static ?int $navigationSort = 31;

    protected string $view = 'filament.pages.system-diagnostics-page';

    #[Locked]
    public array $diagnostics = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('operations.view') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('diagnostics.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.study_methodology');
    }

    public function getTitle(): string
    {
        return __('diagnostics.title');
    }

    public function mount(): void
    {
        $this->refreshDiagnostics();
    }

    public function refreshDiagnostics(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->diagnostics = app(SystemDiagnosticsService::class)->snapshot();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('refreshDiagnostics')->label(__('diagnostics.refresh'))->action(fn () => $this->refreshDiagnostics())];
    }
}
