<?php

namespace App\Providers;

use App\Events\IncidentConfirmed;
use App\Events\IncidentResolved;
use App\Events\SslCertificateExpiring;
use App\Events\SystemHealthProblemDetected;
use App\Events\SystemHealthRecovered;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Monitoring\Contracts\DnsResolver;
use App\Monitoring\Contracts\SocketProbe;
use App\Monitoring\Contracts\SslCertificateProbe;
use App\Monitoring\Support\NativeDnsResolver;
use App\Monitoring\Support\NativeSocketProbe;
use App\Monitoring\Support\NativeSslCertificateProbe;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use App\Services\SystemHealthService;
use Illuminate\Auth\Events\Login;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(SocketProbe::class, NativeSocketProbe::class);
        $this->app->bind(SslCertificateProbe::class, NativeSslCertificateProbe::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->save();
                app(AuditLogger::class)->log('user.logged_in', $event->user, 'User logged in', actor: $event->user);
            }
        });
        Queue::looping(fn (): mixed => app(SystemHealthService::class)->recordQueueHeartbeat());
        Queue::after(fn (JobProcessed $event): mixed => app(SystemHealthService::class)->recordQueueJobSucceeded());
        Queue::failing(fn (JobFailed $event): mixed => app(SystemHealthService::class)->recordQueueJobFailed());
        Event::listen(IncidentConfirmed::class, fn (IncidentConfirmed $event) => app(NotificationDispatcher::class)->incident(ServiceIncident::findOrFail($event->incidentId), 'incident_confirmed'));
        Event::listen(IncidentResolved::class, fn (IncidentResolved $event) => app(NotificationDispatcher::class)->incident(ServiceIncident::findOrFail($event->incidentId), 'incident_resolved'));
        Event::listen(SslCertificateExpiring::class, fn (SslCertificateExpiring $event) => app(NotificationDispatcher::class)->ssl(ServiceCheck::with('monitoredService')->findOrFail($event->checkId), $event->threshold));
        Event::listen(SystemHealthProblemDetected::class, fn (SystemHealthProblemDetected $event) => app(NotificationDispatcher::class)->health($event->component, 'problem', $event->cycle));
        Event::listen(SystemHealthRecovered::class, fn (SystemHealthRecovered $event) => app(NotificationDispatcher::class)->health($event->component, 'resolved', $event->cycle));
    }
}
