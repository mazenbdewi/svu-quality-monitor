<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_chart_baselines', function (Blueprint $table): void {
            // Only records created together receive the same identifier. Existing rows
            // remain ungrouped because their membership cannot be reconstructed safely.
            $table->uuid('draft_set_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('control_chart_baselines', fn (Blueprint $table) => $table->dropIndex(['draft_set_id']));
        Schema::table('control_chart_baselines', fn (Blueprint $table) => $table->dropColumn('draft_set_id'));
    }
};
