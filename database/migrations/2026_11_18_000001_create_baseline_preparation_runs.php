<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One durable row serializes dispatch/backpressure decisions across hosts.
        Schema::create('baseline_preparation_scheduler', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_service_id')->default(0);
        });
        DB::table('baseline_preparation_scheduler')->insert(['id' => 1, 'last_service_id' => 0]);

        Schema::create('baseline_preparation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitored_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('policy_version');
            $table->timestamp('cutoff_at');
            $table->string('analysis_timezone', 64);
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('created_drafts')->default(0);
            $table->timestamp('dispatched_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['monitored_service_id', 'policy_version', 'cutoff_at'],
                'baseline_preparation_service_policy_cutoff_unique',
            );
            $table->index(['status', 'dispatched_at'], 'baseline_runs_status_dispatched_index');
            $table->index(['monitored_service_id', 'dispatched_at'], 'baseline_runs_service_dispatched_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baseline_preparation_runs');
        Schema::dropIfExists('baseline_preparation_scheduler');
    }
};
