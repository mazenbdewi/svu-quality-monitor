<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_checks', function (Blueprint $table): void {
            $table->string('source', 20)->default('manual')->after('checked_at');
            $table->index(['monitored_service_id', 'checked_at'], 'service_checks_service_checked_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('service_checks', function (Blueprint $table): void {
            $table->dropIndex('service_checks_service_checked_at_index');
            $table->dropColumn('source');
        });
    }
};
