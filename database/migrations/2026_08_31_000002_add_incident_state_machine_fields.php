<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_services', function (Blueprint $table): void {
            $table->string('operational_state', 30)->default('healthy')->after('is_active');
            $table->unsignedSmallInteger('failure_confirmation_count')->default(2)->after('operational_state');
            $table->unsignedSmallInteger('recovery_confirmation_count')->default(2)->after('failure_confirmation_count');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('recovery_confirmation_count');
            $table->unsignedInteger('consecutive_recovery_successes')->default(0)->after('consecutive_failures');
            $table->timestamp('first_failure_at')->nullable()->after('consecutive_recovery_successes');
            $table->timestamp('recovery_started_at')->nullable()->after('first_failure_at');
            $table->unsignedBigInteger('last_incident_processed_check_id')->nullable()->after('recovery_started_at');
            $table->unsignedSmallInteger('state_transition_count')->default(0)->after('last_incident_processed_check_id');
            $table->timestamp('state_transition_window_started_at')->nullable()->after('state_transition_count');
            $table->boolean('is_flapping')->default(false)->after('state_transition_window_started_at');
        });
        Schema::table('service_incidents', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable()->after('started_at');
            $table->timestamp('resolved_at')->nullable()->after('ended_at');
            $table->unsignedInteger('failure_count')->default(0)->after('duration_minutes');
            $table->string('initial_failure_type')->nullable()->after('incident_type');
            $table->text('initial_failure_message')->nullable()->after('initial_failure_type');
            $table->string('latest_failure_type')->nullable()->after('initial_failure_message');
            $table->text('latest_failure_message')->nullable()->after('latest_failure_type');
        });
    }

    public function down(): void
    {
        Schema::table('service_incidents', function (Blueprint $table): void {
            $table->dropColumn(['confirmed_at', 'resolved_at', 'failure_count', 'initial_failure_type', 'initial_failure_message', 'latest_failure_type', 'latest_failure_message']);
        });
        Schema::table('monitored_services', function (Blueprint $table): void {
            $table->dropColumn(['operational_state', 'failure_confirmation_count', 'recovery_confirmation_count', 'consecutive_failures', 'consecutive_recovery_successes', 'first_failure_at', 'recovery_started_at', 'last_incident_processed_check_id', 'state_transition_count', 'state_transition_window_started_at', 'is_flapping']);
        });
    }
};
