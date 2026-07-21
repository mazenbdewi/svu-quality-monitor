<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemOperationsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_documentation_files_exist(): void
    {
        foreach ([
            'docs/installation.md',
            'docs/operation.md',
            'docs/research-workflow.md',
            'docs/backup-and-maintenance.md',
            'docs/production-checklist.md',
            'docs/minitab-validation.md',
        ] as $path) {
            $this->assertFileExists(base_path($path));
        }
    }

    public function test_readme_contains_project_name_and_documentation_links(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertStringContainsString('SVU Quality Monitor', $readme);
        $this->assertStringContainsString('docs/installation.md', $readme);
        $this->assertStringContainsString('docs/operation.md', $readme);
        $this->assertStringContainsString('docs/minitab-validation.md', $readme);
    }

    public function test_scheduler_entries_are_configured_once(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertSame(1, substr_count($console, "Schedule::command('services:check-due')"));
        $this->assertSame(1, substr_count($console, "Schedule::command('reliability:calculate --period=daily')"));
        $this->assertSame(1, substr_count($console, "Schedule::command('control-charts:calculate --period=daily')"));
        $this->assertStringContainsString('->everyMinute()', $console);
        $this->assertStringContainsString("->dailyAt('00:10')", $console);
        $this->assertStringContainsString("->dailyAt('00:25')", $console);
        $this->assertSame(3, substr_count($console, '->withoutOverlapping()'));
    }

    public function test_system_operations_page_renders_for_authenticated_user(): void
    {
        config(['app.env' => 'local']);

        $this->actingAs(User::factory()->create())
            ->get(SystemOperationsPage::getUrl())
            ->assertOk()
            ->assertSee(__('monitoring.operations.title'))
            ->assertSee('php artisan schedule:work');
    }
}
