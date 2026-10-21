<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\InstitutionSetting;
use App\Services\AdministrativeAudit;
use App\Services\InstitutionTimezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        return __('monitoring.navigation_groups.system');
    }

    public function getTitle(): string
    {
        return __('monitoring.executive.branding.title');
    }

    protected function getHeaderActions(): array
    {
        return [ActionGroup::make([
            Action::make('notificationSettings')->label(NotificationSettingsPage::getNavigationLabel())->url(NotificationSettingsPage::getUrl())->visible(fn (): bool => NotificationSettingsPage::canAccess()),
            Action::make('systemOperations')->label(SystemOperationsPage::getNavigationLabel())->url(SystemOperationsPage::getUrl())->visible(fn (): bool => SystemOperationsPage::canAccess()),
            Action::make('systemDiagnostics')->label(SystemDiagnosticsPage::getNavigationLabel())->url(SystemDiagnosticsPage::getUrl())->visible(fn (): bool => SystemDiagnosticsPage::canAccess()),
            Action::make('users')->label(UserResource::getNavigationLabel())->url(UserResource::getUrl())->visible(fn (): bool => UserResource::canViewAny()),
            Action::make('auditLogs')->label(AuditLogResource::getNavigationLabel())->url(AuditLogResource::getUrl())->visible(fn (): bool => AuditLogResource::canViewAny()),
            Action::make('aboutSystem')->label(AboutSystemPage::getNavigationLabel())->url(AboutSystemPage::getUrl()),
        ])->label(__('monitoring.navigation_groups.system').' · '.__('early_deterioration.more'))];
    }

    public function mount(): void
    {
        $this->form->fill(InstitutionSetting::current()->only(['institution_name', 'institution_logo', 'timezone']));
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('monitoring.executive.branding.title'))->description(__('ux.help.institution'))->schema([
                TextInput::make('institution_name')->label(__('monitoring.executive.branding.name'))->required()->maxLength(255),
                Select::make('timezone')->label(__('institution_timezone.label'))
                    ->options(array_combine(InstitutionTimezone::identifiers(), InstitutionTimezone::identifiers()))
                    ->searchable()->required()->rules([Rule::in(InstitutionTimezone::identifiers())])
                    ->live()->disabled(fn () => ! auth()->user()?->can('institution.manage'))
                    ->helperText(__('institution_timezone.help')),
                Text::make(__('institution_timezone.warning'))->color('warning'),
                Text::make(fn (Get $get): string => __('institution_timezone.current', ['time' => in_array($get('timezone'), InstitutionTimezone::identifiers(), true) ? now($get('timezone'))->format('Y-m-d H:i:s P') : '—'])),
                Text::make(__('institution_timezone.scheduler')),
                TextInput::make('institution_logo')->label(__('monitoring.executive.branding.logo'))->url()->maxLength(2048)->helperText(__('monitoring.executive.branding.logo_help'))->extraInputAttributes(['dir' => 'ltr']),
            ]),
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
        $data = $this->form->getState();
        DB::transaction(function () use ($data): void {
            $setting = InstitutionSetting::query()->lockForUpdate()->findOrFail(InstitutionSetting::current()->id);
            $audit = app(AdministrativeAudit::class);
            $before = $audit->snapshot($setting);
            $setting->update($data);
            $audit->record($setting->fresh(), $before);
        });
        Notification::make()->success()->title(__('monitoring.executive.branding.saved'))->send();
    }
}
