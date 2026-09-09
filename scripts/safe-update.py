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
import urllib.request


class SafeUpdate:
    services = ("app", "scheduler", "queue", "web")

    def __init__(self, args):
        self.args = args
        self.compose = ["docker", "compose", "-f", str(Path(args.compose).resolve())]
        self.state = Path(args.state).resolve()
        self.offline = False
        self.previous = {}
        self.run_dir = None

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
        # No credentials, cookies or redirects to another host are needed.
        with urllib.request.urlopen(self.args.url.rstrip("/") + "/admin/login", timeout=10) as response:
            if response.status != 200:
                raise RuntimeError("Login HTTP check failed")

    def preflight(self):
        self.run(["docker", "info", "--format", "{{.ServerVersion}}"], 30)
        self.dc("config", "--quiet")
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
                raise RuntimeError("Required service missing: " + service)
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
        self.dc("stop", "-t", "120", "scheduler", "queue")
        override = self.run_dir / "previous-images.json"
        override.write_text(json.dumps({"services": {name: {"image": tag} for name, tag in self.previous.items()}}))
        self.run(self.compose + ["-f", str(override), "up", "-d", "--no-deps", "--no-build", "--force-recreate", "app"])
        self.run(self.compose + ["-f", str(override), "up", "-d", "--no-deps", "--no-build", "--force-recreate", "web"])
        self.event("rollback_images_completed", action="Keep workers stopped. Review schema compatibility and pre-update backup before artisan up.")

    def execute(self):
        self.preflight()
        if self.args.dry_run:
            self.event("dry_run_passed", previous_images=self.previous, steps=["pre-update backup", "build", "pending migrations", "maintenance and drain", "migrate and seed", "switch app/scheduler/queue/web", "smoke and HTTP", "failure: old images with maintenance retained"])
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
                backup = self.artisan("backup:create", "--pre-update")
                result = json.loads(backup.splitlines()[-1])
                if not result.get("verified"):
                    raise RuntimeError("Pre-update backup was not verified")
                self.event("backup_created", path=result["path"])
                self.dc("build", *self.services)
                self.event("build_completed")
                pending = self.dc("run", "--rm", "--no-deps", "--entrypoint", "php", "app", "artisan", "migrate:status", "--no-ansi")
                self.event("migration_status", status=pending)
                self.artisan("down", "--retry=60")
                self.offline = True
                self.artisan("queue:restart")
                self.dc("stop", "-t", "120", "scheduler", "queue")
                self.dc("run", "--rm", "--no-deps", "--entrypoint", "php", "app", "artisan", "migrate", "--force")
                self.dc("run", "--rm", "--no-deps", "--entrypoint", "php", "app", "artisan", "db:seed", "--class=RolesAndPermissionsSeeder", "--force")
                self.event("migration_completed")
                self.dc("up", "-d", "--no-deps", "--no-build", "--force-recreate", "app", "scheduler", "queue")
                self.dc("up", "-d", "--no-deps", "--no-build", "--force-recreate", "web")
                new = {s: self.run(["docker", "inspect", "--format", "{{.Image}}", self.dc("ps", "-q", s)]) for s in self.services}
                self.event("containers_switched", new_images=new)
                self.artisan("optimize:clear")
                self.artisan("permission:cache-reset")
                self.artisan("system:smoke-check", "--static")
                # Laravel suppresses Queue::looping in maintenance mode. Keep
                # HTTP closed while releasing workers to prove real heartbeats.
                self.dc("stop", "web")
                self.artisan("up")
                deadline = time.monotonic() + 180
                while True:
                    try:
                        self.artisan("system:smoke-check")
                        break
                    except RuntimeError:
                        if time.monotonic() >= deadline:
                            raise
                        time.sleep(5)
                self.dc("start", "web")
                self.http()
                self.event("health_passed")
            except Exception:
                self.event("update_failed", offline=self.offline)
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
