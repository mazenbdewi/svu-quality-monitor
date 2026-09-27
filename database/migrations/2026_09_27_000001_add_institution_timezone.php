<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institution_settings', fn (Blueprint $table) => $table->string('timezone')->default('Asia/Damascus'));
        // Unknown legacy calendars remain null; never backfill research evidence.
        foreach (['reliability_metrics', 'sla_metrics'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('analysis_timezone')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['reliability_metrics', 'sla_metrics'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('analysis_timezone'));
        }
        Schema::table('institution_settings', fn (Blueprint $table) => $table->dropColumn('timezone'));
    }
};
