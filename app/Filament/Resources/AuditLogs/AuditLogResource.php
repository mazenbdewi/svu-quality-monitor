<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationLabel(): string
    {
        return __('administration.audit.title');
    }

    public static function getModelLabel(): string
    {
        return __('administration.audit.entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('administration.audit.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.system');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function eventLabel(string $event): string
    {
        $events = __('administration.audit.events');

        return $events[$event] ?? $event;
    }

    public static function safeJson(?array $payload): string
    {
        return $payload === null ? '-' : json_encode(app(AuditLogger::class)->sanitize($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('actor.name')->label(__('administration.audit.actor'))->placeholder(__('administration.audit.system')),
            TextEntry::make('event')->label(__('administration.audit.event'))->formatStateUsing(fn ($state) => static::eventLabel($state)),
            TextEntry::make('description')->label(__('administration.audit.description'))->columnSpanFull(),
            TextEntry::make('auditable_type')->label(__('administration.audit.resource'))->placeholder('-'),
            TextEntry::make('auditable_id')->label(__('administration.audit.resource_id'))->placeholder('-'),
            TextEntry::make('created_at')->label(__('administration.audit.timestamp'))->dateTime(),
            TextEntry::make('ip_address')->label(__('administration.audit.ip'))->placeholder('-'),
            TextEntry::make('user_agent')->label(__('administration.audit.user_agent'))->placeholder('-')->columnSpanFull(),
            ...collect(['before', 'after', 'context'])->map(fn ($field) => TextEntry::make('safe_'.$field)
                ->label(__('administration.audit.'.$field))
                ->state(fn (AuditLog $record): string => static::safeJson($record->{$field}))
                ->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere; font-family: monospace;'])
                ->columnSpanFull())->all(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('created_at')->label(__('administration.audit.timestamp'))->dateTime(),
            TextColumn::make('actor.name')->label(__('administration.audit.actor'))->placeholder(__('administration.audit.system')),
            TextColumn::make('event')->label(__('administration.audit.event'))->badge()->formatStateUsing(fn ($state) => static::eventLabel($state)),
            TextColumn::make('auditable_type')->label(__('administration.audit.resource'))->formatStateUsing(fn ($state) => class_basename($state)),
            TextColumn::make('description')->label(__('administration.audit.description'))->wrap(),
            TextColumn::make('ip_address')->label(__('administration.audit.ip')),
        ])->filters([
            SelectFilter::make('actor')->label(__('administration.audit.actor'))->relationship('actor', 'name')->searchable()->preload(),
            SelectFilter::make('event')->label(__('administration.audit.event'))->options(fn () => AuditLog::query()->distinct()->pluck('event', 'event')->map(fn ($event) => static::eventLabel($event))->all()),
            SelectFilter::make('auditable_type')->label(__('administration.audit.resource'))->options(fn () => AuditLog::query()->whereNotNull('auditable_type')->distinct()->pluck('auditable_type', 'auditable_type')->map(fn ($type) => class_basename($type))->all()),
            Filter::make('created_at')->label(__('administration.audit.dates'))->schema([
                DatePicker::make('from')->label(__('administration.audit.from')),
                DatePicker::make('to')->label(__('administration.audit.to'))->afterOrEqual('from'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                ->when($data['to'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
        ])->recordActions([ViewAction::make()->authorize(fn (AuditLog $record): bool => static::canView($record))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/'), 'view' => ViewAuditLog::route('/{record}')];
    }
}
