<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('control_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_service_id')->constrained('monitored_services')->cascadeOnDelete();
            $table->string('chart_type', 50);
            $table->string('metric_name', 80);
            $table->string('period_type', 20)->default('daily');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->decimal('center_line', 14, 4)->nullable();
            $table->decimal('ucl', 14, 4)->nullable();
            $table->decimal('lcl', 14, 4)->nullable();
            $table->unsignedInteger('points_count')->default(0);
            $table->unsignedInteger('out_of_control_count')->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique([
                'monitored_service_id',
                'chart_type',
                'metric_name',
                'period_type',
                'period_start',
                'period_end',
            ], 'control_charts_unique_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('control_charts');
    }
};
