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
        Schema::create('service_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_service_id')->constrained('monitored_services')->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->boolean('is_success')->default(false);
            $table->boolean('is_slow')->default(false);
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('expected_keyword_found')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_checks');
    }
};
