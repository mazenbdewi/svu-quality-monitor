<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slo_proposals', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('slo_proposals')->whereNull('created_by')->exists()) {
            throw new LogicException('Cannot discard system-created SLO proposal provenance.');
        }
        Schema::table('slo_proposals', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
    }
};
