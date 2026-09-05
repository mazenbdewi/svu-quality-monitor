<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_services', function (Blueprint $table): void {
            $table->boolean('sla_enabled')->default(false)->after('is_active');
            $table->decimal('sla_target_percent', 5, 2)->nullable()->after('sla_enabled');
        });

        Schema::create('sla_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitored_service_id')->constrained()->cascadeOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->decimal('target_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('eligible_observation_seconds')->default(0);
            $table->unsignedBigInteger('planned_maintenance_seconds')->default(0);
            $table->unsignedBigInteger('unplanned_downtime_seconds')->default(0);
            $table->decimal('availability_percent', 9, 6)->nullable();
            $table->decimal('allowed_downtime_seconds', 18, 6)->nullable();
            $table->unsignedBigInteger('error_budget_consumed_seconds')->default(0);
            $table->decimal('error_budget_remaining_seconds', 18, 6)->nullable();
            $table->decimal('error_budget_consumed_percent', 12, 6)->nullable();
            $table->string('status', 20)->default('not_configured');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['monitored_service_id', 'period_start', 'period_end'], 'sla_metrics_unique_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_metrics');

        Schema::table('monitored_services', function (Blueprint $table): void {
            $table->dropColumn(['sla_enabled', 'sla_target_percent']);
        });
    }
};
