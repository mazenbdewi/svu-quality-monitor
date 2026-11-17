<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitored_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('monitoring_schedule_epoch_id')->constrained('monitoring_schedule_epochs')->restrictOnDelete();
            $table->dateTime('nominal_due_at_utc');
            $table->string('status', 32);
            $table->string('reason', 64)->nullable();
            $table->dateTime('dispatched_at_utc')->nullable();
            $table->dateTime('started_at_utc')->nullable();
            $table->dateTime('completed_at_utc')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->foreignId('service_check_id')->nullable()->unique()->constrained('service_checks')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['monitored_service_id', 'nominal_due_at_utc'], 'monitoring_opportunity_unique_slot');
            $table->index(['status', 'nominal_due_at_utc'], 'monitoring_opportunity_status_due');
            $table->index(['monitoring_schedule_epoch_id', 'nominal_due_at_utc'], 'monitoring_opportunity_epoch_due');
        });
    }

    public function down(): void
    {
        $hasOpportunities = Schema::hasTable('monitoring_opportunities') && DB::table('monitoring_opportunities')->exists();
        $hasEpochs = Schema::hasTable('monitoring_schedule_epochs') && DB::table('monitoring_schedule_epochs')->exists();
        if ($hasOpportunities || $hasEpochs) {
            throw new LogicException('Retained fixed-cadence records require separate archival review.');
        }
        Schema::dropIfExists('monitoring_opportunities');
    }
};
