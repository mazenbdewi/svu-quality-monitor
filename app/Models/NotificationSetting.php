<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    protected $fillable = ['telegram_enabled', 'telegram_bot_token', 'telegram_chat_id', 'email_enabled', 'email_recipients', 'ssl_expiry_notifications_enabled'];

    protected function casts(): array
    {
        return ['telegram_enabled' => 'boolean', 'telegram_bot_token' => 'encrypted', 'email_enabled' => 'boolean', 'email_recipients' => 'array', 'ssl_expiry_notifications_enabled' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['ssl_expiry_notifications_enabled' => true]);
    }
}
