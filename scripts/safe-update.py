#!/usr/bin/env python3
"""Host-side Compose deployment. No git operations or database rollback."""
import argparse
import datetime
import fcntl
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request


class SafeUpdate:
    services = ("app", "scheduler", "queue", "baseline_queue", "web")
    required_existing_services = ("app", "scheduler", "queue", "web")

    def __init__(self, args):
        self.args = args
        self.compose = ["docker", "compose", "-f", str(Path(args.compose).resolve())]
        self.state = Path(args.state).resolve()
        self.offline = False
        self.previous = {}
        self.workers = ("scheduler", "queue", "baseline_queue")
        self.run_dir = None
        self.stage = "preflight"

    def run(self, command, timeout=3600):
        result = subprocess.run(command, capture_output=True, text=True, timeout=timeout)
        if result.returncode:
            raise RuntimeError("Command failed: " + " ".join(command[:3]))
        return result.stdout.strip()

    def dc(self, *args):
        return self.run(self.compose + list(args))

    def artisan(self, *args):
        return self.dc("exec", "-T", "app", "php", "artisan", *args)

    def event(self, name, **data):
        record = {"at": datetime.datetime.now(datetime.timezone.utc).isoformat(), "event": name, **data}
        print(json.dumps(record), flush=True)
        if self.run_dir:
            with (self.run_dir / "events.jsonl").open("a") as stream:
                stream.write(json.dumps(record) + "\n")

    def http(self):
        # Container start returns before nginx necessarily accepts connections.
        # Bound the readiness wait and keep failures visible after the grace.
        deadline = time.monotonic() + 30
        while True:
            try:
                with urllib.request.urlopen(self.args.url.rstrip("/") + "/admin/login", timeout=10) as response:
                    if response.status != 200:
                        raise RuntimeError("Login HTTP check failed")
                return
            except (urllib.error.URLError, TimeoutError, ConnectionError):
                if time.monotonic() >= deadline:
                    raise RuntimeError("Login HTTP check failed") from None
                time.sleep(1)

    def preflight(self):
        self.run(["docker", "info", "--format", "{{.ServerVersion}}"], 30)
        self.dc("config", "--quiet")
        definition = json.loads(self.dc("--profile", "*", "config", "--format", "json"))
        # Preserve enabled optional workers without activating inactive profiles.
        profiles = set(os.environ.get("COMPOSE_PROFILES", "").split(","))
        optional = []
        for name, config in definition.get("services", {}).items():
            if name in self.services or not name.endswith("_queue"):
                continue
            if self.dc("ps", "-q", name) or profiles.intersection(config.get("profiles", [])):
                optional.append(name)
        self.services = (*self.services, *optional)
        self.workers = (*self.workers, *optional)
        self.artisan("system:smoke-check")
        self.dc("exec", "-T", "app", "php", "-r", 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); exit(config("app.maintenance.driver") === "file" ? 0 : 1);')
        self.http()
        self.dc("exec", "-T", "app", "php", "-r", 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $p=config("backup.directory"); exit(is_dir($p) && is_writable($p) && disk_free_space($p)>config("backup.minimum_free_bytes") ? 0 : 1);')
        parent = self.state if self.state.exists() else self.state.parent
        if not parent.is_dir() or not os.access(parent, os.W_OK) or shutil.disk_usage(parent).free < 1073741824:
            raise RuntimeError("Update state disk is not writable or has insufficient free space")
        for service in self.services:
            container = self.dc("ps", "-q", service)
            if not container:
                if service in self.required_existing_services:
                    raise RuntimeError("Required service missing: " + service)
                # The dedicated baseline worker is new in this release. Its
                # absence is valid only before the first upgraded deployment.
                continue
            self.previous[service] = self.recovery_image(container)

    def recovery_image(self, container):
        # Docker stores may expose config or manifest digests. Try both immutable
        # identifiers recorded on the container, verifying addressability.
        for template in ("{{.Image}}", '{{index .Config.Labels "com.docker.compose.image"}}'):
            image = self.run(["docker", "inspect", "--format", template, container])
            if image and image != "<no value>":
                try:
                    return self.run(["docker", "image", "inspect", "--format", "{{.Id}}", image])
                except RuntimeError:
                    pass
        # Fail closed if the original image was removed; never use a mutable tag
        # which may already point to the newly built release.
        raise RuntimeError("Original recovery image is unavailable; preserve it before updating")

    def rollback_images(self):
        self.event("rollback_images_started", warning="Database is NOT rolled back; maintenance will remain enabled")
        # Stop the HTTP entry point even if the replacement PHP app cannot boot.
        self.dc("stop", "web")
        try:
            self.artisan("down", "--retry=60")
        except Exception:
            # File-based maintenance is a preflight requirement. No Laravel/DB boot needed.
            self.dc("run", "--rm", "--no-deps", "--entrypoint", "php", "app", "-r",
                    '$p="storage/framework/down"; $t=$p.".recovery"; $data=json_encode(["time"=>time(),"status"=>503,"retry"=>60]); if(file_put_contents($t,$data)===false || !rename($t,$p)) exit(1);')
        self.dc("stop", "-t", "120", *self.workers)
        self.dc("rm", "-f", "baseline_queue")
        override = self.run_dir / "previous-images.json"
        override.write_text(json.dumps({"services": {name: {"image": tag} for name, tag in self.previous.items()}}))
        self.run(self.compose + ["-f", str(override), "up", "-d", "--no-deps", "--no-build", "--force-recreate", "app"])
        self.run(self.compose + ["-f", str(override), "up", "-d", "--no-deps", "--no-build", "--force-recreate", "web"])
        self.event("rollback_images_completed", action="Keep workers stopped. Review schema compatibility and pre-update backup before artisan up.")

    def execute(self):
        self.preflight()
        if self.args.dry_run:
            self.event("dry_run_passed", previous_images=self.previous, steps=["pre-update backup", "build", "pending migrations", "maintenance and drain", "migrate", "switch app/scheduler/queue/baseline_queue/web", "worker heartbeat smoke and HTTP", "failure: old images with maintenance retained and baseline jobs preserved"])
            return
        os.umask(0o077)
        self.state.mkdir(mode=0o700, parents=True, exist_ok=True)
        with (self.state / ".lock").open("w") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.run_dir = self.state / datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
            self.run_dir.mkdir(mode=0o700)
            # Local immutable recovery tags keep images reachable even after rebuilding latest.
            for service, image in self.previous.copy().items():
                tag = "svu-recovery-" + service + ":" + self.run_dir.name.lower()
                self.run(["docker", "tag", image, tag])
                self.previous[service] = tag
            self.event("update_started", previous_images=self.previous)
            try:
                self.stage = "backup"
                backup = self.artisan("backup:create", "--pre-update")
                result = json.loads(backup.splitlines()[-1])
                if not result.get("verified"):
                    raise RuntimeError("Pre-update backup was not verified")
                self.event("backup_created", path=result["path"])
                self.stage = "build"
                self.dc("build", *self.services)
                # Freeze the new build too: unrelated rebuilds must not change
                # one-off migrations or containers midway through a deployment.
                release = {}
                definition = json.loads(self.dc("--profile", "*", "config", "--format", "json"))
                for service in self.services:
                    config = definition["services"][service]
                    image = config.get("image") or definition["name"] + "-" + service
                    tag = "svu-release-" + service + ":" + self.run_dir.name.lower()
                    self.run(["docker", "tag", image, tag])
                    release[service] = {"image": tag}
                pinned = self.run_dir / "release-images.json"
                pinned.write_text(json.dumps({"services": release}))
                self.compose += ["-f", str(pinned)]
                self.event("build_completed", release_images=release)
                self.stage = "migration_status"
                pending = self.dc("run", "--rm", "--no-deps", "--entrypoint", "php", "app", "artisan", "migrate:status", "--no-ansi")
                self.event("migration_status", status=pending)
                self.stage = "maintenance_and_drain"
                self.artisan("down", "--retry=60")
                self.offline = True
                self.artisan("queue:restart")
                self.dc("stop", "-t", "120", *self.workers)
                self.stage = "migration"
                self.dc("run", "--rm", "--no-deps", "--entrypoint", "php", "app", "artisan", "migrate", "--force")
                self.event("migration_completed")
                self.stage = "recreate"
                self.dc("up", "-d", "--no-deps", "--no-build", "--force-recreate", "app", *self.workers)
                self.dc("up", "-d", "--no-deps", "--no-build", "--force-recreate", "web")
                new = {s: self.run(["docker", "inspect", "--format", "{{.Image}}", self.dc("ps", "-q", s)]) for s in self.services}
                self.event("containers_switched", new_images=new)
                self.stage = "clear_caches"
                self.artisan("optimize:clear")
                self.stage = "static_health"
                self.artisan("system:smoke-check", "--static")
                # Laravel suppresses Queue::looping in maintenance mode. Keep
                # HTTP closed while releasing workers to prove real heartbeats.
                self.stage = "close_http"
                self.dc("stop", "web")
                self.stage = "leave_maintenance"
                self.artisan("up")
                self.stage = "worker_health"
                deadline = time.monotonic() + 180
                while True:
                    try:
                        self.artisan("system:smoke-check")
                        break
                    except RuntimeError:
                        if time.monotonic() >= deadline:
                            raise
                        time.sleep(5)
                self.stage = "start_http"
                self.dc("start", "web")
                self.stage = "http_health"
                self.http()
                self.event("health_passed")
            except Exception as error:
                self.event("update_failed", offline=self.offline, stage=self.stage, error_type=type(error).__name__)
                if self.offline:
                    try:
                        self.rollback_images()
                    except Exception:
                        self.event("rollback_incomplete", action="Keep site offline; use recovery tags and backup from this log. Do not run artisan up.")
                raise RuntimeError("Update failed. Review safe operational log and rollback runbook.") from None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--compose", default="compose.yaml")
    parser.add_argument("--state", default="update-state")
    parser.add_argument("--url", required=True, help="Trusted base URL served by this deployment")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    try:
        SafeUpdate(args).execute()
    except Exception as error:
        print("Safe update stopped: " + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
