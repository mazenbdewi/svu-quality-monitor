<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_chart_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_service_id')->constrained()->restrictOnDelete();
            $table->string('chart_type', 20);
            $table->string('metric_name', 60);
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->string('active_key', 64)->nullable()->unique();
            $table->string('aggregation_interval', 20);
            $table->string('analysis_timezone', 64);
            $table->timestamp('baseline_start');
            $table->timestamp('baseline_end');
            $table->timestamp('data_cutoff');
            $table->string('calculation_version', 40);
            $table->string('method_identifier', 80);
            $table->string('eligibility_policy_version', 40);
            foreach (['sample_counts', 'coverage_context', 'parameters', 'configuration_snapshot', 'review_context'] as $column) {
                $table->json($column);
            }
            $table->string('compatibility_fingerprint', 64);
            $table->string('membership_digest', 64);
            $table->timestamp('sealed_at')->nullable();
            foreach (['created_by', 'reviewed_by', 'approved_by', 'retired_by', 'rejected_by'] as $column) {
                // Preserve historical actor identifiers even if an account is removed.
                $table->unsignedBigInteger($column)->nullable();
            }
            foreach (['reviewed_at', 'approved_at', 'retired_at', 'rejected_at', 'effective_from', 'effective_to'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->text('review_notes')->nullable();
            $table->boolean('limitations_acknowledged')->default(false);
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->unique(['monitored_service_id', 'chart_type', 'version'], 'baseline_family_version_unique');
        });
        Schema::create('control_chart_baseline_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_chart_baseline_id')->constrained()->restrictOnDelete();
            // Not a live FK: retention/deletion of raw checks cannot erase research membership.
            $table->unsignedBigInteger('service_check_id');
            $table->unsignedInteger('sequence');
            $table->boolean('included');
            $table->json('exclusion_reasons');
            $table->json('measurement');
            $table->unique(['control_chart_baseline_id', 'service_check_id'], 'baseline_sample_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_chart_baseline_samples');
        Schema::dropIfExists('control_chart_baselines');
    }
};
