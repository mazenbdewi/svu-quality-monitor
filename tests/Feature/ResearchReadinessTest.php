<?php

namespace Tests\Feature;

use App\Models\MonitoredService;
use App\Services\Research\ResearchConfiguration;
use App\Services\Research\ResearchPackage;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ResearchReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_seeding_is_factory_free_and_creates_no_demo_user(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('permissions', ['name' => 'control_charts.baselines.manage']);
    }

    public function test_local_seeding_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_snapshot_and_package_exclude_endpoint_credentials_and_include_comparison_data(): void
    {
        $this->travelTo(Carbon::parse('2026-09-18 12:00:00', 'UTC'));
        $service = MonitoredService::factory()->create(['created_at' => '2026-09-01', 'url' => 'https://secret:password@example.test/?token=never-export', 'expected_keyword' => 'private-keyword', 'check_config' => ['headers' => ['Authorization' => 'Bearer secret-token']]]);
        foreach (['automatic', 'manual'] as $source) {
            $service->serviceChecks()->create(['checked_at' => now()->subHour(), 'source' => $source, 'check_type' => 'http', 'is_success' => true, 'is_slow' => true, 'performance_status' => 'critical', 'response_time_ms' => 5000, 'metadata' => ['credential' => 'hidden-meta'], 'error_message' => 'hidden-error']);
        }
        $snapshot = app(ResearchConfiguration::class)->snapshot();
        $this->assertSame('UTC', $snapshot['clock']['application_timezone']);
        $this->assertSame('Asia/Damascus', $snapshot['clock']['analysis_timezone']);
        $this->assertNotEmpty($snapshot['code']['source_sha256']);
        $directory = sys_get_temp_dir().'/r5-package-'.bin2hex(random_bytes(8));
        try {
            $manifest = app(ResearchPackage::class)->export(now()->subDay(), now(), $directory);
            $contents = '';
            foreach ($manifest['sha256'] as $file => $hash) {
                $this->assertSame($hash, hash_file('sha256', $directory.'/'.$file));
                $contents .= file_get_contents($directory.'/'.$file);
            }
            foreach (['never-export', 'private-keyword', 'secret-token', 'hidden-meta', 'hidden-error', 'https://secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $contents);
            }
            $checks = json_decode(file_get_contents($directory.'/checks.json'), true);
            $this->assertCount(2, $checks);
            $this->assertSame('critical', $checks[0]['performance_status']);
            $this->assertNotNull($checks[0]['created_at']);
            $this->assertTrue($checks[0]['binary_eligible']);
            $this->assertFalse($checks[1]['binary_eligible']);
            $this->assertFileExists($directory.'/live_signals.json');
            $this->assertFileExists($directory.'/retrospective_signals.json');
            $this->assertSame(1, $manifest['row_counts']['exclusions.json']);
            $this->assertDatabaseCount('spc_research_runs', 0);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_configuration_command_emits_json(): void
    {
        $this->artisan('research:configuration')->assertSuccessful();
    }

    public function test_package_preserves_incident_followup_after_requested_period(): void
    {
        $this->travelTo(Carbon::parse('2026-09-18 12:00:00', 'UTC'));
        $s = MonitoredService::factory()->create(['created_at' => now()->subDay()]);
        $i = $s->serviceIncidents()->create(['started_at' => now()->subMinutes(30), 'confirmed_at' => now()->subMinutes(29), 'status' => 'open', 'incident_type' => 'availability', 'severity' => 'critical']);
        $directory = sys_get_temp_dir().'/r5-followup-'.bin2hex(random_bytes(8));
        try {
            app(ResearchPackage::class)->export(now()->subHours(3), now()->subHour(), $directory);
            $rows = json_decode(file_get_contents($directory.'/incidents.json'), true);
            $this->assertSame($i->id, $rows[0]['id']);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
