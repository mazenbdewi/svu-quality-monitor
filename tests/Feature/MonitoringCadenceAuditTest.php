<?php

namespace Tests\Feature;

use App\Jobs\CheckMonitoredServiceJob;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Services\MonitoringCoverageCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringCadenceAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The clock is deliberately pinned at each cron minute; the real database
     * queue and worker are used, but no external HTTP request is made.
     */
    public static function intervals(): array
    {
        return ['one_minute' => [1], 'five_minutes' => [5], 'fifteen_minutes' => [15]];
    }

    #[DataProvider('intervals')]
    public function test_minute_polling_can_produce_interval_plus_one_minute_check_gaps(int $interval): void
    {
        $origin = Carbon::parse('2026-11-17 00:00:00 UTC');
        $this->travelTo($origin);
        config()->set('queue.default', 'database');
        Http::fake(['https://cadence.test/*' => Http::response('OK', 200)]);

        $service = MonitoredService::factory()->create([
            'url' => "https://cadence.test/{$interval}",
            'expected_keyword' => null,
            'check_type' => 'http',
            'check_interval_minutes' => $interval,
            'is_active' => true,
        ]);
        $started = [];
        $completed = [];
        Queue::before(function (JobProcessing $event) use (&$started): void {
            if ($event->job->resolveName() === CheckMonitoredServiceJob::class) {
                $started[] = now()->utc()->toIso8601String();
            }
        });
        Queue::after(function (JobProcessed $event) use (&$completed): void {
            if ($event->job->resolveName() === CheckMonitoredServiceJob::class) {
                $completed[] = now()->utc()->toIso8601String();
            }
        });

        $dispatches = [];
        $insertions = [];
        $due = [];
        for ($minute = 0; $minute <= 2 * ($interval + 1); $minute++) {
            $poll = $origin->copy()->addMinutes($minute);
            $this->travelTo($poll);
            $last = $service->serviceChecks()->latest('checked_at')->first();
            $dueAt = $last?->checked_at->copy()->addMinutes($interval);
            $isDue = $dueAt === null || $dueAt->lte($poll);

            $this->artisan('services:check-due')->assertSuccessful();
            $queued = DB::table('jobs')->where('queue', 'default')->get();
            $this->assertCount($isDue ? 1 : 0, $queued, "Unexpected queued jobs at minute {$minute} for interval {$interval}.");

            if (! $isDue) {
                continue;
            }

            $dispatches[] = $poll->toIso8601String();
            $insertions[] = (int) $queued->sole()->created_at;
            $due[] = $dueAt?->toIso8601String();
            $this->travelTo($poll->copy()->addSeconds(5));
            $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--tries' => 1, '--sleep' => 0])->assertSuccessful();
            $this->assertDatabaseCount('jobs', 0);
        }

        $checks = $service->serviceChecks()->orderBy('checked_at')->get();
        $this->assertCount(3, $checks);
        $this->assertSame([0, $interval + 1, 2 * ($interval + 1)], array_map(
            fn (string $at): int => (int) $origin->diffInMinutes(Carbon::parse($at)),
            $dispatches,
        ));
        $this->assertSame([null, $origin->copy()->addMinutes($interval)->addSeconds(5)->toIso8601String(), $origin->copy()->addMinutes(2 * $interval + 1)->addSeconds(5)->toIso8601String()], $due);
        $this->assertSame(array_map(fn (string $at): int => Carbon::parse($at)->timestamp, $dispatches), $insertions);
        $this->assertSame(array_map(fn (string $at): string => Carbon::parse($at)->addSeconds(5)->toIso8601String(), $dispatches), $started);
        $this->assertSame($started, $completed);
        $this->assertSame($started, $checks->map(fn (ServiceCheck $check): string => $check->checked_at->utc()->toIso8601String())->all());
        $this->assertTrue($checks->every(fn (ServiceCheck $check): bool => $check->source === ServiceCheck::SOURCE_AUTOMATIC && $check->is_success));
        $this->assertSame([$interval + 1, $interval + 1], [
            (int) $checks[0]->checked_at->diffInMinutes($checks[1]->checked_at),
            (int) $checks[1]->checked_at->diffInMinutes($checks[2]->checked_at),
        ]);

        if ($interval === 1) {
            $this->travelTo($origin->copy()->addMinutes(6));
            $coverage = app(MonitoringCoverageCalculator::class)->calculate($service, $origin, $origin->copy()->addMinutes(6));
            $this->assertSame(6, $coverage['expected_automatic_checks']);
            $this->assertSame(3, $coverage['covered_intervals']);
            $this->assertSame(3, $coverage['missing_checks']);
            $this->assertSame('insufficient_coverage', $coverage['status']);
        }

        $this->travelBack();
    }

    public function test_repeated_poll_does_not_enqueue_or_count_a_duplicate_pending_job(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 00:00:00 UTC'));
        config()->set('queue.default', 'database');
        config()->set('cache.default', 'database');
        MonitoredService::factory()->create(['url' => 'https://cadence.test/repeat', 'expected_keyword' => null, 'is_active' => true]);

        $this->artisan('services:check-due')->expectsOutput('Dispatched services count: 1')->expectsOutput('Duplicate pending jobs count: 0')->assertSuccessful();
        $this->artisan('services:check-due')->expectsOutput('Dispatched services count: 0')->expectsOutput('Duplicate pending jobs count: 1')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
        $this->travelBack();
    }

    public function test_manual_check_postpones_automatic_due_time_because_latest_check_includes_all_sources(): void
    {
        $origin = Carbon::parse('2026-11-17 00:00:00 UTC');
        $this->travelTo($origin);
        config()->set('queue.default', 'database');
        $service = MonitoredService::factory()->create(['check_interval_minutes' => 5, 'is_active' => true]);
        foreach ([['00:00:05', 'automatic'], ['00:04:59', 'manual']] as [$time, $source]) {
            $service->serviceChecks()->create([
                'checked_at' => "2026-11-17 {$time}",
                'source' => $source,
                'check_type' => 'http',
                'is_success' => true,
                'is_slow' => false,
                'is_during_maintenance' => false,
            ]);
        }

        $this->travelTo($origin->copy()->addMinutes(5));
        $this->artisan('services:check-due')->expectsOutput('Due services count: 0')->assertSuccessful();
        $this->travelTo($origin->copy()->addMinutes(9));
        $this->artisan('services:check-due')->expectsOutput('Due services count: 0')->assertSuccessful();
        $this->travelTo($origin->copy()->addMinutes(10));
        $this->artisan('services:check-due')->expectsOutput('Due services count: 1')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
        $this->assertSame(1, $service->serviceChecks()->where('source', ServiceCheck::SOURCE_AUTOMATIC)->count());
        $this->travelBack();
    }
}
