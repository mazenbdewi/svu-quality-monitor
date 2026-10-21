<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EarlyDeteriorationDetection;
use App\Filament\Pages\InstitutionSettingsPage;
use App\Filament\Pages\PerformanceAnalysis;
use App\Filament\Pages\ReportsPage;
use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use App\Filament\Widgets\CurrentServiceStatusWidget;
use App\Filament\Widgets\ExecutiveDashboardWidget;
use App\Filament\Widgets\LatestAlertsAndSignalsWidget;
use App\Filament\Widgets\OverviewStatsWidget;
use App\Filament\Widgets\ResearchFindingsWidget;
use App\Filament\Widgets\ResponseTimeTrendWidget;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => $builder->items([
                NavigationItem::make(fn (): string => __('filament-panels::pages/dashboard.title'))
                    ->icon(Dashboard::getNavigationIcon())->url(fn (): string => Dashboard::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.dashboard')),
                NavigationItem::make(fn (): string => MonitoredServiceResource::getNavigationLabel())
                    ->icon(MonitoredServiceResource::getNavigationIcon())->url(fn (): string => MonitoredServiceResource::getUrl())
                    ->visible(fn (): bool => MonitoredServiceResource::canViewAny())
                    ->isActiveWhen(fn (): bool => request()->is('admin/monitored-services*')),
                NavigationItem::make(fn (): string => __('performance_analysis.open'))
                    ->icon(PerformanceAnalysis::getNavigationIcon())->url(fn (): string => PerformanceAnalysis::getUrl())
                    ->visible(fn (): bool => PerformanceAnalysis::canAccess())
                    ->isActiveWhen(fn (): bool => request()->is('admin/performance-analysis')),
                NavigationItem::make(fn (): string => __('early_deterioration.title'))
                    ->icon(EarlyDeteriorationDetection::getNavigationIcon())->url(fn (): string => EarlyDeteriorationDetection::getUrl())
                    ->visible(fn (): bool => EarlyDeteriorationDetection::canAccess())
                    ->isActiveWhen(fn (): bool => request()->is('admin/early-deterioration-detection')),
                NavigationItem::make(fn (): string => ReportsPage::getNavigationLabel())
                    ->icon(ReportsPage::getNavigationIcon())->url(fn (): string => ReportsPage::getUrl())
                    ->visible(fn (): bool => ReportsPage::canAccess())
                    ->isActiveWhen(fn (): bool => request()->is('admin/reports')),
                NavigationItem::make(fn (): string => __('monitoring.navigation_groups.system'))
                    ->icon(InstitutionSettingsPage::getNavigationIcon())->url(fn (): string => InstitutionSettingsPage::getUrl())
                    ->visible(fn (): bool => InstitutionSettingsPage::canAccess())
                    ->isActiveWhen(fn (): bool => request()->is('admin/institution-settings')),
            ]))
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('monitoring.navigation_groups.monitoring')),
                NavigationGroup::make()->label(fn (): string => __('monitoring.navigation_groups.analysis_reliability')),
                NavigationGroup::make()->label(fn (): string => __('monitoring.navigation_groups.reports')),
                NavigationGroup::make()->label(fn (): string => __('monitoring.navigation_groups.advanced_research')),
                NavigationGroup::make()->label(fn (): string => __('monitoring.navigation_groups.system')),
                NavigationGroup::make()->label(fn (): string => __('monitoring.navigation_groups.study_methodology')),
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn () => view('filament.locale-direction'),
            )
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn () => view('filament.components.language-switcher'),
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn () => view('filament.components.language-switcher'),
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn () => view('filament.components.system-health-indicator'),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                ExecutiveDashboardWidget::class,
                OverviewStatsWidget::class,
                ResearchFindingsWidget::class,
                CurrentServiceStatusWidget::class,
                ResponseTimeTrendWidget::class,
                LatestAlertsAndSignalsWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
