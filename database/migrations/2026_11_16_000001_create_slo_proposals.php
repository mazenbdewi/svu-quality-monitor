<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slo_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_service_id')->constrained()->restrictOnDelete();
            $table->string('policy_version', 80);
            $table->dateTime('calibration_start');
            $table->dateTime('calibration_end');
            $table->dateTime('calibration_cutoff');
            $table->string('analysis_timezone', 64);
            $table->char('configuration_fingerprint', 64);
            $table->char('identity_digest', 64)->unique();
            $table->char('payload_digest', 64);
            $table->json('calibration_payload');
            $table->unsignedInteger('proposed_latency_ms')->nullable();
            $table->decimal('proposed_compliance_percent', 5, 2);
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->boolean('limitations_acknowledged')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('approval_rationale')->nullable();
            $table->unsignedBigInteger('retired_by')->nullable();
            $table->dateTime('retired_at')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->timestamps();
            $table->index(['monitored_service_id', 'status']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('slo_proposals') && DB::table('slo_proposals')->exists()) {
            throw new LogicException('Cannot roll back retained SLO research proposals; archive and review them first.');
        }
        Schema::dropIfExists('slo_proposals');
    }
};
