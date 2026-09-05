<?php

namespace App\Filament\Resources\NotificationDeliveries;

use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Models\NotificationDelivery;
use App\Services\NotificationDeliveryRetryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class NotificationDeliveryResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('notifications.view') ?? false;
    }

    public static function getModelLabel(): string
    {
        return __('monitoring.notifications.delivery');
    }

    public static function getPluralModelLabel(): string
    {
        return __('monitoring.notifications.deliveries');
    }

    public static function getNavigationLabel(): string
    {
        return __('monitoring.notifications.deliveries');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.monitoring');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('event_type')->label(__('monitoring.notifications.event_type')),
            TextColumn::make('channel')->label(__('monitoring.notifications.channel'))->badge(),
            TextColumn::make('monitoredService.name')->label(__('monitoring.report_columns.service_name')),
            TextColumn::make('service_incident_id')->label(__('monitoring.notifications.incident')),
            TextColumn::make('status')->label(__('monitoring.notifications.status'))->badge(),
            TextColumn::make('attempt_number')->label(__('monitoring.notifications.attempt_number')),
            TextColumn::make('attempted_at')->label(__('monitoring.notifications.attempted_at'))->dateTime(),
            TextColumn::make('sent_at')->label(__('monitoring.notifications.sent_at'))->dateTime(),
            TextColumn::make('safe_error_message')->label(__('monitoring.notifications.safe_error_message'))->wrap(),
        ])->recordActions([
            Action::make('retry')->label(__('monitoring.notifications.retry'))->visible(fn (NotificationDelivery $record): bool => $record->status === 'failed' && (auth()->user()?->can('notifications.manage') ?? false))->action(function (NotificationDelivery $record): void {
                abort_unless(auth()->user()?->can('notifications.manage'), 403);
                app(NotificationDeliveryRetryService::class)->retry($record);
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationDeliveries::route('/')];
    }
}
