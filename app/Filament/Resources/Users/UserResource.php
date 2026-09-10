<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\UserAdministrationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getNavigationLabel(): string
    {
        return __('administration.users');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.system');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('users.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('users.create') ?? false;
    }

    public static function canEdit($record): bool
    {
        return (auth()->user()?->can('users.update') ?? false) && (! $record->hasRole('super_admin') || auth()->user()->hasRole('super_admin'));
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('administration.user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('administration.users');
    }

    public static function roleOptions(): array
    {
        return Role::query()->where('guard_name', 'web')
            ->when(! auth()->user()?->hasRole('super_admin'), fn ($query) => $query->where('name', '!=', 'super_admin'))
            ->pluck('name', 'name')->map(fn ($name) => __('administration.roles.'.$name))->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('administration.name'))->required()->maxLength(255),
            TextInput::make('email')->label(__('administration.email'))->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('password')->label(__('administration.password'))->password()->autocomplete('new-password')
                ->dehydrated(fn ($state) => filled($state))->required(fn (string $operation) => $operation === 'create'),
            Select::make('role')->label(__('administration.role'))->options(fn () => static::roleOptions())->required(),
            Toggle::make('is_active')->label(__('administration.active'))->default(true),
        ]);
    }

    public static function statusAction(bool $active): Action
    {
        return Action::make($active ? 'activate' : 'deactivate')
            ->label(__('administration.'.($active ? 'activate' : 'deactivate')))
            ->color($active ? 'success' : 'warning')->requiresConfirmation(! $active)
            ->authorize(fn (User $record): bool => static::canEdit($record))
            ->visible(fn (User $record): bool => $record->is_active !== $active)
            ->action(function (User $record) use ($active): void {
                try {
                    $service = app(UserAdministrationService::class);
                    $active ? $service->activate(auth()->user(), $record) : $service->deactivate(auth()->user(), $record);
                    Notification::make()->success()->title(__('administration.audit.events.'.($active ? 'user.activated' : 'user.deactivated')))->send();
                } catch (\DomainException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->emptyStateHeading(__('ux.empty.users'))->emptyStateDescription(__('ux.empty.filters'))->columns([
            TextColumn::make('name')->label(__('administration.name'))->searchable(),
            TextColumn::make('email')->label(__('administration.email'))->searchable(),
            TextColumn::make('roles.name')->label(__('administration.role'))->badge()->color('gray')->formatStateUsing(fn ($state) => __('administration.roles.'.$state)),
            TextColumn::make('is_active')->label(__('administration.status'))->badge()
                ->formatStateUsing(fn ($state) => __('administration.'.($state ? 'active' : 'inactive')))
                ->color(fn ($state) => $state ? 'success' : 'gray'),
            TextColumn::make('last_login_at')->label(__('administration.last_login'))->dateTime()->placeholder(__('administration.never')),
            TextColumn::make('created_at')->toggleable(isToggledHiddenByDefault: true)->label(__('administration.created_at'))->dateTime(),
        ])->filters([
            SelectFilter::make('roles')->label(__('administration.role'))->relationship('roles', 'name')
                ->getOptionLabelFromRecordUsing(fn ($record) => __('administration.roles.'.$record->name)),
            TernaryFilter::make('is_active')->label(__('administration.status'))->trueLabel(__('administration.active'))->falseLabel(__('administration.inactive')),
        ])->recordActions([
            EditAction::make()->authorize(fn (User $record): bool => static::canEdit($record)),
            static::statusAction(true), static::statusAction(false),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/'), 'create' => CreateUser::route('/create'), 'edit' => EditUser::route('/{record}/edit')];
    }
}
