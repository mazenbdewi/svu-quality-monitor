<?php

namespace Tests\Feature;

use Tests\Support\ResearchMigrationScenario;
use Tests\TestCase;

class ResearchMigrationUpgradeTest extends TestCase
{
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
