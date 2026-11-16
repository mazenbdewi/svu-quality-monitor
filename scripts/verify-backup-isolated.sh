#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Dedicated project, no production env file, no exposed ports, no persistent volumes.
project="svu-backup-drill-$$"
cleanup() {
    docker compose -p "$project" -f scripts/compose.backup-drill.yaml down --volumes --remove-orphans
}
trap cleanup EXIT
if ! output=$(docker compose -p "$project" -f scripts/compose.backup-drill.yaml up --abort-on-container-exit --exit-code-from verifier 2>&1); then
    printf '%s\n' "$output"
    exit 1
fi
printf '%s\n' "$output"
if ! grep -q '"drill_passed": true' <<< "$output"; then
    printf '%s\n' 'Backup drill did not produce an explicit successful verification result.' >&2
    exit 1
fi
