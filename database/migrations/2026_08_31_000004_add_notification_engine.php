<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('telegram_enabled')->default(false);
            $table->text('telegram_bot_token')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->boolean('email_enabled')->default(false);
            $table->json('email_recipients')->nullable();
            $table->boolean('ssl_expiry_notifications_enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->string('channel');
            $table->foreignId('monitored_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_incident_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->default('pending');
            $table->string('error_category')->nullable();
            $table->string('safe_error_message')->nullable();
            $table->unsignedTinyInteger('attempt_number')->default(0);
            $table->string('deduplication_key')->unique();
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        Schema::create('system_health_alert_states', function (Blueprint $table) {
            $table->id();
            $table->string('component')->unique();
            $table->string('status')->default('healthy');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::table('monitored_services', fn (Blueprint $table) => $table->boolean('notifications_enabled')->default(true)->after('is_active'));
    }

    public function down(): void
    {
        Schema::table('monitored_services', fn (Blueprint $table) => $table->dropColumn('notifications_enabled'));
        Schema::dropIfExists('system_health_alert_states');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_settings');
    }
};
