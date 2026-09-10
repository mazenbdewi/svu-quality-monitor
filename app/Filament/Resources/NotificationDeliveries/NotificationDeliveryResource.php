<?php

namespace App\Filament\Resources\NotificationDeliveries;

use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Models\NotificationDelivery;
use App\Services\NotificationDeliveryRetryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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
        return $table->emptyStateIcon(Heroicon::OutlinedBellAlert)->emptyStateHeading(__('ux.empty.deliveries'))->emptyStateDescription(__('ux.empty.deliveries_help'))->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('event_type')->label(__('monitoring.notifications.event_type'))->formatStateUsing(fn (string $state): string => __('ux.delivery_events')[$state] ?? $state),
            TextColumn::make('channel')->label(__('monitoring.notifications.channel'))->badge()->color('gray'),
            TextColumn::make('monitoredService.name')->label(__('monitoring.report_columns.service_name')),
            TextColumn::make('service_incident_id')->toggleable(isToggledHiddenByDefault: true)->label(__('monitoring.notifications.incident')),
            TextColumn::make('status')->label(__('monitoring.notifications.status'))->badge()->formatStateUsing(fn (string $state): string => __('ux.delivery_statuses')[$state] ?? $state)->color(fn (string $state): string => match ($state) {
                'sent' => 'success', 'failed' => 'danger', 'pending' => 'warning', default => 'gray'
            }),
            TextColumn::make('attempt_number')->toggleable(isToggledHiddenByDefault: true)->label(__('monitoring.notifications.attempt_number')),
            TextColumn::make('attempted_at')->toggleable(isToggledHiddenByDefault: true)->label(__('monitoring.notifications.attempted_at'))->dateTime(),
            TextColumn::make('sent_at')->label(__('monitoring.notifications.sent_at'))->dateTime(),
            TextColumn::make('safe_error_message')->label(__('monitoring.notifications.safe_error_message'))->wrap(),
        ])->recordActions([
            Action::make('retry')->label(__('monitoring.notifications.retry'))->visible(fn (NotificationDelivery $record): bool => $record->status === 'failed' && (auth()->user()?->can('notifications.manage') ?? false))->action(function (NotificationDelivery $record): void {
                abort_unless(auth()->user()?->can('notifications.manage'), 403);
                try {
                    if (app(NotificationDeliveryRetryService::class)->retry($record, auth()->user())) {
                        Notification::make()->success()->title(__('ux.retry_queued'))->send();
                    } else {
                        Notification::make()->info()->title(__('ux.already_sent'))->send();
                    }
                } catch (\RuntimeException $exception) {
                    report($exception);
                    Notification::make()->danger()->title(__('ux.retry_failed'))->send();
                }
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationDeliveries::route('/')];
    }
}
