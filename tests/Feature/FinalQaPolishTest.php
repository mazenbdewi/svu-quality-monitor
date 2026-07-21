<?php

namespace Tests\Feature;

use App\Filament\Pages\AboutSystemPage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ReportsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Tests\TestCase;

class FinalQaPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_system_page_renders_for_authenticated_user(): void
    {
        config(['app.env' => 'local']);

        $this->actingAs(User::factory()->create())
            ->get(AboutSystemPage::getUrl())
            ->assertOk()
            ->assertSee(__('monitoring.about.title'))
            ->assertSee(__('monitoring.about.body'))
            ->assertSee(__('monitoring.minitab_validation.external_validation_title'));
    }

    public function test_dashboard_page_renders_without_http_checks(): void
    {
        config(['app.env' => 'local']);

        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create())
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee(__('monitoring.dashboard.title'));
    }

    public function test_reports_page_still_renders_with_default_export_format(): void
    {
        Livewire::test(ReportsPage::class)
            ->assertSet('data.report_type', 'service_checks')
            ->assertSet('data.export_format', 'excel');
    }

    public function test_polish_translation_keys_exist_for_supported_locales(): void
    {
        $keys = [
            'monitoring.navigation_groups.monitoring',
            'monitoring.navigation_groups.analysis_reliability',
            'monitoring.navigation_groups.reports',
            'monitoring.navigation_groups.study_methodology',
            'monitoring.empty_states.no_data',
            'monitoring.empty_states.no_checks',
            'monitoring.empty_states.no_reliability_metrics',
            'monitoring.reports.helpers.report_type',
            'monitoring.reports.helpers.export_format',
            'monitoring.about.navigation_label',
            'monitoring.about.body',
        ];

        foreach (['en', 'ar'] as $locale) {
            foreach ($keys as $key) {
                $this->assertTrue(Lang::has($key, $locale), "{$key} is missing for {$locale}");
            }
        }
    }
}
