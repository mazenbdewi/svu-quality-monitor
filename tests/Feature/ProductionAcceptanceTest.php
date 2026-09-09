<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductionAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public static function locales(): array
    {
        return [['ar'], ['en']];
    }

    #[DataProvider('locales')]
    public function test_all_core_pages_render_without_literal_translation_keys(string $locale): void
    {
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true])->assignRole('super_admin');
        $this->actingAs($user)->withSession(['locale' => $locale]);
        $urls = [];
        foreach (Filament::getPanel('admin')->getPages() as $page) {
            $urls[] = $page::getUrl();
        }
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $urls[] = $resource::getUrl();
        }
        foreach (array_unique($urls) as $url) {
            $response = $this->get($url)->assertOk();
            $html = $response->getContent();
            $this->assertStringContainsString('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', $html, $url);
            $text = strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/si', '', $html));
            preg_match_all('/\b(?:monitoring|administration|backup|diagnostics)\.[a-zA-Z_][a-zA-Z0-9_.]+/', $text, $matches);
            $this->assertSame([], array_unique($matches[0]), $url);
        }
    }

    public function test_application_translation_keys_match_between_arabic_and_english(): void
    {
        foreach (['monitoring', 'administration', 'backup', 'diagnostics'] as $file) {
            $ar = array_keys(Arr::dot(require lang_path("ar/{$file}.php")));
            $en = array_keys(Arr::dot(require lang_path("en/{$file}.php")));
            sort($ar);
            sort($en);
            $this->assertSame([], array_values(array_diff($en, $ar)), "Missing Arabic: {$file}");
            $this->assertSame([], array_values(array_diff($ar, $en)), "Missing English: {$file}");
        }
    }
}
