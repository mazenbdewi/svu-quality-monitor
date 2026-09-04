<?php

namespace App\Filament\Resources\MaintenanceWindows;

use App\Filament\Resources\MaintenanceWindows\Pages\CreateMaintenanceWindow;
use App\Filament\Resources\MaintenanceWindows\Pages\EditMaintenanceWindow;
use App\Filament\Resources\MaintenanceWindows\Pages\ListMaintenanceWindows;
use App\Models\MaintenanceWindow;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class MaintenanceWindowResource extends Resource
{
    protected static ?string $model = MaintenanceWindow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('monitoring.maintenance.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('monitoring.maintenance.resource.plural_model_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('monitoring.maintenance.resource.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.monitoring');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('monitoring.maintenance.fields.name'))->required()->maxLength(255),
            Toggle::make('applies_to_all_services')
                ->label(__('monitoring.maintenance.fields.applies_to_all_services'))
                ->live(),
            Select::make('monitoredServices')
                ->label(__('monitoring.maintenance.fields.services'))
                ->relationship('monitoredServices', 'name')
                ->multiple()->searchable()->preload()->required(fn (Get $get): bool => ! $get('applies_to_all_services'))
                ->visible(fn (Get $get): bool => ! $get('applies_to_all_services'))
                ->columnSpanFull(),
            DateTimePicker::make('starts_at')
                ->label(__('monitoring.maintenance.fields.starts_at'))
                ->required()
                ->disabled(fn (?MaintenanceWindow $record): bool => $record !== null && $record->statusAt() !== 'scheduled'),
            DateTimePicker::make('ends_at')
                ->label(__('monitoring.maintenance.fields.ends_at'))
                ->required()->after('starts_at')
                ->disabled(fn (?MaintenanceWindow $record): bool => $record !== null && $record->statusAt() === 'completed'),
            Textarea::make('description')->label(__('monitoring.maintenance.fields.description'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('starts_at', 'desc')->columns([
            TextColumn::make('name')->label(__('monitoring.maintenance.table.name'))->searchable()->sortable(),
            TextColumn::make('services')->label(__('monitoring.maintenance.table.services'))
                ->getStateUsing(fn (MaintenanceWindow $record): string => $record->applies_to_all_services
                    ? __('monitoring.maintenance.all_services')
                    : $record->monitoredServices->pluck('name')->join(', '))
                ->wrap(),
            TextColumn::make('starts_at')->label(__('monitoring.maintenance.table.starts_at'))->dateTime()->sortable(),
            TextColumn::make('ends_at')->label(__('monitoring.maintenance.table.ends_at'))->dateTime()->sortable(),
            TextColumn::make('duration')->label(__('monitoring.maintenance.table.duration'))
                ->getStateUsing(fn (MaintenanceWindow $record): string => trans_choice('monitoring.units.minutes', $record->durationMinutes(), ['count' => number_format($record->durationMinutes())])),
            TextColumn::make('status')->label(__('monitoring.maintenance.table.status'))->badge()
                ->getStateUsing(fn (MaintenanceWindow $record): string => __('monitoring.maintenance.statuses.'.$record->statusAt()))
                ->color(fn (MaintenanceWindow $record): string => match ($record->statusAt()) {
                    'active' => 'warning', 'scheduled' => 'info', default => 'gray'
                }),
        ])->recordActions([
            EditAction::make()->label(__('monitoring.actions.edit'))->visible(fn (MaintenanceWindow $record): bool => $record->statusAt() !== 'completed'),
            DeleteAction::make()->label(__('monitoring.actions.delete'))->visible(fn (MaintenanceWindow $record): bool => $record->statusAt() === 'scheduled'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceWindows::route('/'),
            'create' => CreateMaintenanceWindow::route('/create'),
            'edit' => EditMaintenanceWindow::route('/{record}/edit'),
        ];
    }
}
