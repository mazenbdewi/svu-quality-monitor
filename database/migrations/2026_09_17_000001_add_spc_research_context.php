<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // InnoDB may use the old composite unique index to support the service FK.
        // Give that FK an independent index before dropping the composite constraint.
        if (! Schema::hasIndex('control_charts', 'control_charts_service_reference_index')) {
            Schema::table('control_charts', fn (Blueprint $table) => $table->index('monitored_service_id', 'control_charts_service_reference_index'));
        }
        Schema::table('control_charts', function (Blueprint $table) {
            // A safe rollback deliberately leaves the legacy unique constraint absent.
            if (Schema::hasIndex('control_charts', 'control_charts_unique_period')) {
                $table->dropUnique('control_charts_unique_period');
            }
            $table->string('analysis_identity', 64)->nullable()->unique();
            $table->string('analysis_timezone', 64)->nullable();
            $table->string('aggregation_interval', 20)->nullable();
            $table->string('analysis_mode', 20)->nullable();
            $table->string('calculation_version', 30)->nullable();
            $table->timestamp('data_cutoff')->nullable();
            $table->json('research_context')->nullable();
        });
        Schema::table('control_chart_points', fn (Blueprint $table) => $table->json('research_context')->nullable());
    }

    public function down(): void
    {
        // The old unique constraint cannot represent coexisting hourly/daily analyses.
        // Do not restore it by deleting valid research records during rollback.
        Schema::table('control_chart_points', fn (Blueprint $table) => $table->dropColumn('research_context'));
        Schema::table('control_charts', function (Blueprint $table) {
            $table->dropUnique(['analysis_identity']);
            $table->dropColumn(['analysis_identity', 'analysis_timezone', 'aggregation_interval', 'analysis_mode', 'calculation_version', 'data_cutoff', 'research_context']);
        });
    }
};
