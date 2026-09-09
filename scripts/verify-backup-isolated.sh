#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Dedicated project, no production env file, no exposed ports, no persistent volumes.
project="svu-backup-drill-$$"
cleanup() {
    docker compose -p "$project" -f scripts/compose.backup-drill.yaml down --volumes --remove-orphans
}
trap cleanup EXIT
docker compose -p "$project" -f scripts/compose.backup-drill.yaml up --abort-on-container-exit --exit-code-from verifier
