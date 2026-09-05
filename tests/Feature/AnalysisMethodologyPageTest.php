<?php

namespace Tests\Feature;

use App\Filament\Pages\AnalysisMethodologyPage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalysisMethodologyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_methodology_page_renders_for_authenticated_user_without_http_checks(): void
    {
        config(['app.env' => 'local']);

        Http::preventStrayRequests();

        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('viewer');

        $this->actingAs($user)
            ->get(AnalysisMethodologyPage::getUrl())
            ->assertOk()
            ->assertSee(__('monitoring.methodology.title'))
            ->assertSee(__('monitoring.methodology.sections.system_idea.title'))
            ->assertSee(__('monitoring.minitab_validation.title'));
    }

    public function test_minitab_validation_guide_document_exists(): void
    {
        $this->assertFileExists(base_path('docs/minitab-validation.md'));
    }
}
