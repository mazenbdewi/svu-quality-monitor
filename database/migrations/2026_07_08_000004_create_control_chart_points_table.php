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
        Schema::create('control_chart_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_chart_id')->constrained('control_charts')->cascadeOnDelete();
            $table->timestamp('point_time');
            $table->decimal('value', 14, 4);
            $table->decimal('center_line', 14, 4)->nullable();
            $table->decimal('ucl', 14, 4)->nullable();
            $table->decimal('lcl', 14, 4)->nullable();
            $table->unsignedInteger('sample_size')->nullable();
            $table->unsignedInteger('failed_count')->nullable();
            $table->boolean('is_out_of_control')->default(false);
            $table->string('signal_type')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('control_chart_points');
    }
};
