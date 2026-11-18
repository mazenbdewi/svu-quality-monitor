#!/usr/bin/env python3
"""Disposable Docker acceptance harness. Never targets a live Compose project."""
import concurrent.futures
import json
import os
from pathlib import Path
import statistics
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]
ENV = Path('/tmp/svu-release-remediation.env')
OVERRIDE = Path('/tmp/svu-release-remediation-compose.yaml')
DC = ['docker', 'compose', '--env-file', str(ENV), '-p', 'svu-release-remediation', '-f', str(ROOT / 'compose.yaml'), '-f', str(OVERRIDE)]
APP = 'svu-release-remediation-app-1'
NORMAL = 'svu-release-remediation-instrumented-normal'
BASELINE = 'svu-release-remediation-instrumented-baseline'
RESULT = {'fixture': {'services': 20, 'historical_checks': 161280, 'step_minutes': 5}, 'scenarios': {}}


def run(args, timeout=300):
    r = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
    if r.returncode:
        raise RuntimeError('Command failed: ' + ' '.join(args[:6]) + '\n' + r.stderr[-1500:] + r.stdout[-1500:])
    return r.stdout.strip()


def fixture(mode, *args):
    return json.loads(run(['docker', 'exec', APP, 'php', 'tests/Benchmarks/baseline_queue_workload.php', mode, *args]))


def start(name, service, queue):
    run(DC + ['run', '-d', '--no-deps', '--name', name, '--entrypoint', 'php', service, '-d', 'memory_limit=192M', 'tests/Benchmarks/instrumented_worker.php', queue])
    # `compose run` omits the service's production restart policy. Restore it
    # so Laravel's normal memory recycling behaves exactly like compose up.
    run(['docker', 'update', '--restart', 'unless-stopped', name])


def stop(name):
    run(['docker', 'stop', '-t', '65', name])
    run(['docker', 'rm', name])


def stats(baseline=True):
    names = [NORMAL, 'svu-release-remediation-db-1'] + ([BASELINE] if baseline else [])
    return [json.loads(line) for line in run(['docker', 'stats', '--no-stream', '--format', '{{json .}}', *names]).splitlines()]


def drain(baseline=True, deadline=600):
    samples = []
    end = time.monotonic() + deadline
    while time.monotonic() < end:
        report = fixture('report')
        if report['normal_depth'] == 0 and (not baseline or report['baseline_depth'] == 0):
            return report, samples
        # Docker stats samples CPU over a real interval.
        samples.extend(stats(baseline))
        time.sleep(0.5)
    raise RuntimeError('Queue did not drain: ' + json.dumps(report))


def timings():
    rows = [json.loads(line) for line in run(['docker', 'exec', APP, 'cat', 'storage/app/remediation-job-timings.jsonl']).splitlines()]
    out = {}
    for queue in ['default', 'baseline-preparation']:
        values = [r['duration_ms'] for r in rows if r['queue'] == queue and 'CheckMonitoredServiceJob' in r['class'] or r['queue'] == queue == 'baseline-preparation']
        if values:
            out[queue] = {'count': len(values), 'mean_ms': statistics.mean(values), 'max_ms': max(values), 'p95_ms': sorted(values)[max(0, int(len(values) * .95) - 1)]}
    return out


def reset_timing():
    run(['docker', 'exec', APP, 'sh', '-c', ': > storage/app/remediation-job-timings.jsonl'])


if os.environ.get('REMEDIATION_DOCKER_ACCEPTANCE') != '1':
    raise SystemExit('Set REMEDIATION_DOCKER_ACCEPTANCE=1 for the disposable rehearsal stack.')
assert ENV.is_file() and OVERRIDE.is_file()
assert fixture('report')['baseline_depth'] == 0
fixture('seed')
reset_timing()
start(NORMAL, 'queue', 'default')
dispatch = fixture('dispatch', 'normal')
report, samples = drain(baseline=False)
RESULT['scenarios']['normal_only'] = {'dispatch': dispatch, 'report': report, 'timings': timings(), 'resource_samples': samples}
assert report['services_without_a_new_check'] == 0 and report['failed_jobs'] == 0
print('normal_only complete', flush=True)

fixture('seed')
reset_timing()
start(BASELINE, 'baseline_queue', 'baseline-preparation')
dispatch = fixture('dispatch')
report, samples = drain()
RESULT['scenarios']['concurrent'] = {'dispatch': dispatch, 'report': report, 'timings': timings(), 'resource_samples': samples}
assert report['services_without_a_new_check'] == 0 and report['failed_jobs'] == 0 and report['baseline_completed'] == 5
print('concurrent complete', flush=True)
stop(BASELINE)

# Confirm an idle worker keeps emitting loop heartbeats before the outage.
before = report['baseline_health']['last_heartbeat']
start(BASELINE, 'baseline_queue', 'baseline-preparation')
time.sleep(17)
after = fixture('report')['baseline_health']['last_heartbeat']
assert before != after
stop(BASELINE)

fixture('seed')
reset_timing()
outage = fixture('outage')
assert outage['depth'] == 10
assert sum(r['dispatched'] for r in outage['daily_runs']) == 10
normal_during_outage = fixture('dispatch', 'normal')
report, _ = drain(baseline=False)
assert report['services_without_a_new_check'] == 0
RESULT['scenarios']['outage'] = {'daily_dispatch': outage, 'normal_dispatch': normal_during_outage, 'report': report, 'idle_heartbeat_advanced': True}
print('15 daily outage runs bounded at 10 jobs', flush=True)

# Kill a real worker after a database queue reservation. Do not modify reserved_at.
start(BASELINE, 'baseline_queue', 'baseline-preparation')
end = time.monotonic() + 30
while time.monotonic() < end:
    if fixture('report')['baseline_health']['processing_jobs']:
        break
else:
    raise RuntimeError('No real baseline reservation observed')
run(['docker', 'update', '--restart', 'no', BASELINE])
run(['docker', 'kill', '--signal', 'KILL', BASELINE])
run(['docker', 'rm', BASELINE])
interrupted = fixture('report')
start(BASELINE, 'baseline_queue', 'baseline-preparation')
report, samples = drain(deadline=900)
assert report['baseline_completed'] == 10 and report['failed_jobs'] == 0
recovery = []
for _ in range(4):
    recovery.append(fixture('dispatch', 'baseline'))
    report, more = drain()
    samples.extend(more)
assert report['baseline_completed'] == 20 and report['draft_baselines'] == 60
assert report['approved_baselines'] == 0 and report['active_baselines'] == 0
RESULT['scenarios']['restart_recovery'] = {'interrupted': interrupted, 'report': report, 'dispatches': recovery, 'timings': timings(), 'resource_samples': samples}
print('worker restart recovered all 20 services and 60 drafts', flush=True)
stop(BASELINE)

# Eight independent scheduler processes race over a fresh 20-service fixture.
fixture('seed')
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
    counts = list(pool.map(lambda _: run(['docker', 'exec', APP, 'php', 'artisan', 'baselines:prepare-due']), range(8)))
report = fixture('report')
assert report['baseline_depth'] == 10 and report['baseline_runs'] == 10
RESULT['scenarios']['concurrent_schedulers'] = {'commands': counts, 'report': report}
stop(NORMAL)
Path('/tmp/remediation-workload-results.json').write_text(json.dumps(RESULT, indent=2))
print('all Docker workload assertions passed', flush=True)
