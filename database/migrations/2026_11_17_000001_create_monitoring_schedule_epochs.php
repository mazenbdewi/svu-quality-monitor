<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_schedule_epochs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitored_service_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('generation');
            $table->string('policy_version', 64);
            $table->unsignedInteger('interval_minutes');
            $table->char('configuration_fingerprint', 64);
            $table->json('configuration_snapshot');
            $table->dateTime('effective_from_utc');
            $table->dateTime('effective_until_utc')->nullable();
            $table->dateTime('next_nominal_due_at_utc');
            $table->string('end_reason', 40)->nullable();
            // MySQL permits many NULLs in a unique index, but only one open epoch per service.
            $table->string('active_key', 64)->nullable()->unique();
            $table->timestamps();
            $table->unique(['monitored_service_id', 'generation'], 'monitoring_epoch_generation_unique');
            $table->index(['monitored_service_id', 'effective_from_utc'], 'monitoring_epoch_period_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('monitoring_schedule_epochs') && DB::table('monitoring_schedule_epochs')->exists()) {
            throw new LogicException('Retained monitoring schedule epochs require separate archival review.');
        }
        Schema::dropIfExists('monitoring_schedule_epochs');
    }
};
