<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_services', function (Blueprint $table): void {
            $table->string('check_type', 20)->default('http')->after('url');
            $table->text('check_config')->nullable()->after('critical_response_ms');
        });

        Schema::table('service_checks', function (Blueprint $table): void {
            $table->string('check_type', 20)->default('http')->after('source');
            $table->string('performance_status', 20)->nullable()->after('is_slow');
            $table->json('metadata')->nullable()->after('expected_keyword_found');
        });
    }

    public function down(): void
    {
        Schema::table('service_checks', function (Blueprint $table): void {
            $table->dropColumn(['check_type', 'performance_status', 'metadata']);
        });
        Schema::table('monitored_services', function (Blueprint $table): void {
            $table->dropColumn(['check_type', 'check_config']);
        });
    }
};
