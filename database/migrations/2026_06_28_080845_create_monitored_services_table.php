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
        Schema::create('monitored_services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 2048);
            $table->string('category')->nullable();
            $table->unsignedSmallInteger('expected_status_code')->default(200);
            $table->string('expected_keyword')->nullable();
            $table->unsignedInteger('check_interval_minutes')->default(15);
            $table->unsignedInteger('warning_response_ms')->default(1500);
            $table->unsignedInteger('critical_response_ms')->default(3000);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitored_services');
    }
};
