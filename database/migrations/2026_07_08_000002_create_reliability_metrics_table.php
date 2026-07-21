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
        Schema::create('reliability_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_service_id')->constrained('monitored_services')->cascadeOnDelete();
            $table->string('period_type')->default('daily');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedInteger('total_checks')->default(0);
            $table->unsignedInteger('successful_checks')->default(0);
            $table->unsignedInteger('failed_checks')->default(0);
            $table->unsignedInteger('incidents_count')->default(0);
            $table->unsignedInteger('uptime_minutes')->default(0);
            $table->unsignedInteger('downtime_minutes')->default(0);
            $table->decimal('availability_percent', 8, 4)->default(0);
            $table->decimal('mtbf_minutes', 12, 2)->nullable();
            $table->decimal('mttr_minutes', 12, 2)->nullable();
            $table->decimal('failure_rate', 12, 8)->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique([
                'monitored_service_id',
                'period_type',
                'period_start',
                'period_end',
            ], 'reliability_metrics_unique_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reliability_metrics');
    }
};
