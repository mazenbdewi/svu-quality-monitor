#!/usr/bin/env python3
"""Run the unchanged legacy monitoring command every minute in the isolated workload."""
import json
import os
from pathlib import Path
import subprocess
import time

if os.environ.get('REMEDIATION_DOCKER_ACCEPTANCE') != '1':
    raise SystemExit('Isolated workload opt-in required')
rows = []
for _ in range(12):
    if 'worker restart recovered' in Path('/tmp/remediation-workload.log').read_text():
        break
    start = time.monotonic()
    result = subprocess.run(['docker', 'exec', 'svu-release-remediation-app-1', 'php', 'artisan', 'services:check-due'], capture_output=True, text=True, timeout=45)
    rows.append({'at': time.time(), 'dispatch_ms': (time.monotonic()-start)*1000, 'exit_code': result.returncode, 'summary': result.stdout.strip()})
    Path('/tmp/remediation-monitoring-rounds.json').write_text(json.dumps(rows, indent=2))
    if result.returncode:
        raise RuntimeError('Monitoring dispatch failed')
    time.sleep(max(0, 60-(time.monotonic()-start)))
print('minute monitoring polls:', len(rows))
