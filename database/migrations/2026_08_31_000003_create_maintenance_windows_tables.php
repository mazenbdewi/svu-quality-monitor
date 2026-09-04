<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('applies_to_all_services')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('maintenance_window_monitored_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_window_id');
            $table->unsignedBigInteger('monitored_service_id');
            $table->foreign('maintenance_window_id', 'maintenance_window_service_window_fk')
                ->references('id')->on('maintenance_windows')->cascadeOnDelete();
            $table->foreign('monitored_service_id', 'maintenance_window_service_service_fk')
                ->references('id')->on('monitored_services')->cascadeOnDelete();
            $table->unique(['maintenance_window_id', 'monitored_service_id'], 'maintenance_window_service_unique');
        });

        Schema::table('service_checks', function (Blueprint $table) {
            $table->boolean('is_during_maintenance')->default(false)->after('source');
            $table->foreignId('maintenance_window_id')->nullable()->after('is_during_maintenance')->constrained()->nullOnDelete();
            $table->index(['monitored_service_id', 'is_during_maintenance', 'checked_at'], 'checks_maintenance_lookup');
        });

        Schema::table('reliability_metrics', function (Blueprint $table) {
            $table->unsignedInteger('planned_maintenance_minutes')->default(0)->after('downtime_minutes');
            $table->unsignedInteger('observation_minutes')->default(0)->after('planned_maintenance_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('reliability_metrics', function (Blueprint $table) {
            $table->dropColumn(['planned_maintenance_minutes', 'observation_minutes']);
        });

        Schema::table('service_checks', function (Blueprint $table) {
            $table->dropIndex('checks_maintenance_lookup');
            $table->dropConstrainedForeignId('maintenance_window_id');
            $table->dropColumn('is_during_maintenance');
        });

        Schema::dropIfExists('maintenance_window_monitored_service');
        Schema::dropIfExists('maintenance_windows');
    }
};
