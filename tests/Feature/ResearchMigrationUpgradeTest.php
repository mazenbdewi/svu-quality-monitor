<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ResearchMigrationScenario;
use Tests\TestCase;

class ResearchMigrationUpgradeTest extends TestCase
{
    private bool $usesPersistentDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $this->usesPersistentDatabase = config("database.connections.{$connection}.database") !== ':memory:';
        if ($this->usesPersistentDatabase) {
            Schema::dropAllTables();
        }
    }

    protected function tearDown(): void
    {
        if ($this->usesPersistentDatabase) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        parent::tearDown();
    }

    public function test_monitoring_index_rollback_preserves_the_foreign_key_and_can_be_reapplied(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn ($path) => basename($path) <= '2026_08_31_000000_add_source_and_operational_index_to_service_checks_table.php'));
        $this->artisan('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true])->assertExitCode(0);
        $id = DB::table('monitored_services')->insertGetId(['name' => 'FK rollback sentinel', 'url' => 'https://example.invalid']);
        DB::table('service_checks')->insert(['monitored_service_id' => $id, 'checked_at' => now()]);

        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasColumn('service_checks', 'source'));
        DB::table('monitored_services')->where('id', $id)->delete();
        $this->assertDatabaseCount('service_checks', 0);
        $this->artisan('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('service_checks', 'source'));
    }

    public function test_legacy_data_survives_upgrade_rollback_and_reapplication(): void
    {
        $result = ResearchMigrationScenario::run();
        $this->assertSame(4, $result['research_migrations']);
        $this->assertSame('passed', $result['upgrade']);
        $this->assertSame('passed', $result['rollback']);
        $this->assertSame('passed', $result['reapply']);
        $this->assertCount(12, $result['preserved_tables']);
    }
}
