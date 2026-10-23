<?php

namespace Tests\Feature;

use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ControlChartBaselineListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_reads_large_existing_baselines_in_descending_id_order_without_relationship_group_sort(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $service = MonitoredService::factory()->create(['name' => 'Research HTTP']);
        $payload = json_encode(['measurements' => array_fill(0, 3000, ['latency' => 123.456, 'reason' => 'synthetic sample'])], JSON_THROW_ON_ERROR);

        foreach (['i_chart', 'mr_chart', 'p_chart'] as $type) {
            DB::table('control_chart_baselines')->insert([
                'monitored_service_id' => $service->id,
                'chart_type' => $type,
                'metric_name' => 'test',
                'version' => 1,
                'status' => 'draft',
                'aggregation_interval' => $type === 'p_chart' ? 'hourly' : 'raw',
                'analysis_timezone' => 'Asia/Damascus',
                'baseline_start' => '2026-10-01 00:00:00',
                'baseline_end' => '2026-10-02 00:00:00',
                'data_cutoff' => '2026-10-02 00:00:00',
                'calculation_version' => 'test',
                'method_identifier' => 'test',
                'eligibility_policy_version' => 'test',
                'sample_counts' => $payload,
                'coverage_context' => $payload,
                'parameters' => $payload,
                'configuration_snapshot' => $payload,
                'review_context' => $payload,
                'compatibility_fingerprint' => str_repeat('a', 64),
                'membership_digest' => str_repeat('b', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $records = ControlChartBaseline::orderByDesc('id')->get();
        $page = Livewire::actingAs($admin)->test(ListControlChartBaselines::class);
        $page->assertCanSeeTableRecords($records, inOrder: true);
        $this->assertNull($page->instance()->getTableGrouping());
        $sql = $page->instance()->getFilteredSortedTableQuery()->toSql();
        $this->assertStringNotContainsString('select `monitored_services`.`name`', $sql);
        $this->assertMatchesRegularExpression('/order by .*\bid\b.*desc/i', $sql);
        $this->assertDatabaseCount('control_chart_baselines', 3);
    }
}
