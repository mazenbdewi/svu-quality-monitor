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
        Schema::create('service_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_service_id')->constrained('monitored_services')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('incident_type')->nullable();
            $table->string('severity')->default('medium');
            $table->string('status')->default('open');
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_incidents');
    }
};
