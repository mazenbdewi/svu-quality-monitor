<?php

namespace App\Filament\Pages;

use App\Models\NotificationSetting;
use App\Services\AdministrativeAudit;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationSettingsPage extends Page
{
    public static function canAccess(): bool
    {
        return auth()->user()?->can('notifications.manage') ?? false;
    }

    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $slug = 'notification-settings';

    protected string $view = 'filament.pages.notification-settings';

    public function mount(): void
    {
        $setting = NotificationSetting::current();
        $this->data = $setting->only(['telegram_enabled', 'telegram_chat_id', 'email_enabled', 'email_recipients', 'ssl_expiry_notifications_enabled']);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('monitoring.notifications.settings'))->schema([
                Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save'),
            ])->footer([
                Actions::make([
                    Action::make('save')->label(__('monitoring.notifications.save'))->submit('save'),
                    Action::make('testTelegram')->label(__('monitoring.notifications.test_telegram'))->action(fn (): Notification => $this->queueTest('telegram')),
                    Action::make('testEmail')->label(__('monitoring.notifications.test_email'))->action(fn (): Notification => $this->queueTest('email')),
                ]),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Toggle::make('telegram_enabled')->label(__('monitoring.notifications.telegram_enabled')),
            TextInput::make('telegram_bot_token')->password()->revealable(),
            Toggle::make('remove_telegram_token')->label(__('administration.remove_telegram_token'))->default(false),
            TextInput::make('telegram_chat_id'),
            Toggle::make('email_enabled')->label(__('monitoring.notifications.email_enabled')),
            TagsInput::make('email_recipients')->label(__('monitoring.notifications.email_recipients'))->nestedRecursiveRules(['email']),
            Toggle::make('ssl_expiry_notifications_enabled')->label(__('monitoring.notifications.ssl_expiry_notifications_enabled')),
        ]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->can('notifications.manage'), 403);
        $data = $this->form->getState();
        $setting = NotificationSetting::current();
        if (blank($data['telegram_bot_token'] ?? null)) {
            unset($data['telegram_bot_token']);
        }
        if ($data['remove_telegram_token'] ?? false) {
            $data['telegram_bot_token'] = null;
        }
        unset($data['remove_telegram_token']);
        $this->validateSettings($data, $setting);
        DB::transaction(function () use ($setting, $data): void {
            $audit = app(AdministrativeAudit::class);
            $before = $audit->snapshot($setting);
            $setting->update($data);
            $audit->record($setting->fresh(), $before);
        });
        Notification::make()->success()->title(__('monitoring.notifications.saved'))->send();
    }

    private function queueTest(string $channel): Notification
    {
        abort_unless(auth()->user()?->can('notifications.manage'), 403);
        try {
            DB::transaction(function () use ($channel): void {
                $delivery = app(NotificationDispatcher::class)->test($channel);
                $event = 'notification.'.$channel.'_test_requested';
                app(AuditLogger::class)->log($event, $delivery, __('administration.audit.events')[$event], context: ['channel' => $channel]);
            });

            return Notification::make()->success()->title(__($channel === 'telegram' ? 'monitoring.notifications.telegram_queued' : 'monitoring.notifications.email_queued'))->send();
        } catch (ValidationException $exception) {
            return Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    /** @param array<string, mixed> $data */
    private function validateSettings(array $data, NotificationSetting $setting): void
    {
        $token = array_key_exists('telegram_bot_token', $data) ? $data['telegram_bot_token'] : $setting->telegram_bot_token;
        if (($data['telegram_enabled'] ?? false) && (blank($token) || blank($data['telegram_chat_id'] ?? null))) {
            throw ValidationException::withMessages(['data.telegram_bot_token' => __('monitoring.notifications.invalid_telegram')]);
        }
        $recipients = $data['email_recipients'] ?? [];
        if (($data['email_enabled'] ?? false) && (! is_array($recipients) || $recipients === [] || collect($recipients)->contains(fn (mixed $email): bool => ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false))) {
            throw ValidationException::withMessages(['data.email_recipients' => __('monitoring.notifications.invalid_email')]);
        }
    }

    public static function getNavigationLabel(): string
    {
        return __('monitoring.notifications.settings');
    }
}
