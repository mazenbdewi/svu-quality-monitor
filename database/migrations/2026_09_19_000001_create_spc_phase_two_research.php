<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spc_monitoring_evaluations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('service_id')->index();
            $t->unsignedBigInteger('baseline_id')->nullable()->index();
            $t->string('chart_type', 20);
            $t->string('mode', 20);
            $t->string('status', 50);
            $t->timestamp('detected_at');
            $t->timestamp('data_cutoff');
            $t->timestamp('exposure_until')->nullable();
            $t->string('calculation_version', 40);
            $t->json('context');
            $t->timestamps();
        });
        Schema::create('spc_monitoring_points', function (Blueprint $t) {
            $t->id();
            $t->string('identity', 64)->unique();
            $t->unsignedBigInteger('evaluation_id');
            $t->unsignedBigInteger('baseline_id')->index();
            $t->unsignedBigInteger('service_id')->index();
            $t->unsignedInteger('baseline_version');
            $t->string('chart_type', 20);
            $t->string('mode', 20);
            $t->string('source_key', 80);
            $t->timestamp('observed_at');
            $t->timestamp('detected_at');
            $t->timestamp('data_cutoff');
            $t->double('value')->nullable();
            $t->double('center_line')->nullable();
            $t->double('upper_control_limit')->nullable();
            $t->double('lower_control_limit')->nullable();
            $t->unsignedInteger('subgroup_size')->nullable();
            $t->string('aggregation_interval', 20);
            $t->string('status', 50);
            $t->string('calculation_version', 40);
            $t->string('rule_version', 40);
            $t->json('context');
            $t->timestamps();
        });
        Schema::create('spc_signal_episodes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('baseline_id')->index();
            $t->unsignedBigInteger('service_id')->index();
            $t->unsignedInteger('baseline_version');
            $t->string('chart_type', 20);
            $t->string('mode', 20);
            $t->string('direction', 10);
            $t->string('rule_version', 40);
            $t->unsignedInteger('gap_minutes');
            $t->timestamp('episode_started_at');
            $t->timestamp('first_detected_at');
            $t->timestamp('last_detected_at');
            $t->timestamp('closed_at')->nullable();
            $t->unsignedInteger('signal_count');
            $t->timestamps();
        });
        Schema::create('spc_signals', function (Blueprint $t) {
            $t->id();
            $t->string('identity', 64)->unique();
            $t->unsignedBigInteger('point_id')->unique();
            $t->unsignedBigInteger('episode_id')->index();
            $t->unsignedBigInteger('baseline_id')->index();
            $t->unsignedBigInteger('service_id')->index();
            $t->unsignedInteger('baseline_version');
            $t->string('chart_type', 20);
            $t->string('mode', 20);
            $t->timestamp('observed_at');
            $t->timestamp('detected_at');
            $t->timestamp('data_cutoff');
            $t->double('value');
            $t->double('center_line')->nullable();
            $t->double('upper_control_limit')->nullable();
            $t->double('lower_control_limit')->nullable();
            $t->string('rule_code', 40);
            $t->string('rule_version', 40);
            $t->string('calculation_version', 40);
            $t->string('aggregation_interval', 20);
            $t->unsignedInteger('subgroup_size')->nullable();
            $t->string('direction', 10);
            $t->string('status', 30);
            $t->json('context');
            $t->timestamps();
        });
        Schema::create('spc_research_runs', function (Blueprint $t) {
            $t->id();
            $t->string('mode', 20);
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->timestamp('data_cutoff');
            $t->string('evaluation_version', 40);
            $t->string('association_policy_version', 40);
            $t->unsignedInteger('horizon_minutes');
            $t->unsignedInteger('episode_gap_minutes');
            $t->json('baseline_versions');
            $t->json('metrics');
            $t->json('dataset');
            $t->timestamps();
        });
        Schema::create('spc_incident_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('research_run_id')->index();
            $t->unsignedBigInteger('incident_id')->index();
            $t->unsignedBigInteger('episode_id')->index();
            $t->unsignedBigInteger('baseline_id');
            $t->string('chart_type', 20);
            $t->boolean('is_primary');
            $t->boolean('is_nearest');
            $t->unsignedBigInteger('lead_seconds');
            $t->json('context');
            $t->timestamps();
            $t->unique(['research_run_id', 'incident_id', 'episode_id'], 'spc_link_run_unique');
        });
    }

    public function down(): void
    {
        foreach (['spc_incident_links', 'spc_research_runs', 'spc_signals', 'spc_signal_episodes', 'spc_monitoring_points', 'spc_monitoring_evaluations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
