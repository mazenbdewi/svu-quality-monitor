<?php

namespace App\Filament\Pages;

use App\Models\InstitutionSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class InstitutionSettingsPage extends Page
{
    public static function canAccess(): bool
    {
        return auth()->user()?->can('institution.view') ?? false;
    }

    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $slug = 'institution-settings';

    public static function getNavigationLabel(): string
    {
        return __('monitoring.executive.branding.navigation');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.reports');
    }

    public function getTitle(): string
    {
        return __('monitoring.executive.branding.title');
    }

    public function mount(): void
    {
        $this->form->fill(InstitutionSetting::current()->only(['institution_name', 'institution_logo']));
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('institution_name')->label(__('monitoring.executive.branding.name'))->required()->maxLength(255),
            TextInput::make('institution_logo')->label(__('monitoring.executive.branding.logo'))->url()->maxLength(2048)->helperText(__('monitoring.executive.branding.logo_help')),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')->footer([Actions::make([Action::make('save')->label(__('monitoring.actions.save'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->can('institution.manage'), 403);
        InstitutionSetting::current()->update($this->form->getState());
        Notification::make()->success()->title(__('monitoring.executive.branding.saved'))->send();
    }
}
