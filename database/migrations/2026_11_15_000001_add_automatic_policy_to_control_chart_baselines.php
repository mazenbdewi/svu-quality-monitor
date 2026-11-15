<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_chart_baselines', function (Blueprint $table): void {
            $table->string('selection_policy_version', 80)->nullable()->after('eligibility_policy_version')->index();
            $table->string('automatic_identity', 64)->nullable()->after('selection_policy_version')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('control_chart_baselines', function (Blueprint $table): void {
            $table->dropUnique(['automatic_identity']);
            $table->dropIndex(['selection_policy_version']);
            $table->dropColumn(['selection_policy_version', 'automatic_identity']);
        });
    }
};
