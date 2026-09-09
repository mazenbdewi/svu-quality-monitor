import argparse
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock

spec = importlib.util.spec_from_file_location("safe_update", Path(__file__).parents[1] / "scripts/safe-update.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FakeUpdate(module.SafeUpdate):
    def __init__(self, path, fail=None, dry=False):
        super().__init__(argparse.Namespace(compose="compose.yaml", state=path, url="http://localhost", dry_run=dry))
        self.commands = []
        self.fail = fail

    def preflight(self):
        self.commands.append("preflight")
        if self.fail == "preflight":
            raise RuntimeError("preflight")
        self.previous = {s: "sha256:old" for s in self.services}

    def run(self, command, timeout=3600):
        line = " ".join(command)
        self.commands.append(line)
        if self.fail and self.fail in line:
            raise RuntimeError("simulated failure")
        if "backup:create" in line:
            return json.dumps({"path": "/private/pre-update", "verified": True})
        if "migrate:status" in line:
            return "example_migration Pending"
        return "sha256:new"

    def http(self):
        self.commands.append("http")
        if self.fail == "http":
            raise RuntimeError("http")

    def event(self, name, **data):
        self.commands.append(name)


class UpdateTests(unittest.TestCase):
    def test_workers_leave_maintenance_before_heartbeat_check_while_http_stays_closed(self):
        with tempfile.TemporaryDirectory() as folder:
            update = FakeUpdate(folder)
            update.execute()
            commands = update.commands
            static = next(i for i, c in enumerate(commands) if "system:smoke-check --static" in c)
            stop_web = next(i for i, c in enumerate(commands) if "stop web" in c)
            online = next(i for i, c in enumerate(commands) if c.endswith("artisan up"))
            runtime = next(i for i, c in enumerate(commands) if c.endswith("system:smoke-check"))
            start_web = next(i for i, c in enumerate(commands) if "start web" in c)
            self.assertLess(static, stop_web)
            self.assertLess(stop_web, online)
            self.assertLess(online, runtime)
            self.assertLess(runtime, start_web)
            self.assertLess(start_web, commands.index("http"))

    def test_recovery_uses_addressable_compose_image_not_container_config_digest(self):
        with tempfile.TemporaryDirectory() as folder:
            update = FakeUpdate(folder)
            update.run = Mock(side_effect=["sha256:config", RuntimeError("missing"), "sha256:oci-index", "sha256:oci-index"])
            self.assertEqual(update.recovery_image("container"), "sha256:oci-index")
            self.assertEqual(update.run.call_args.args[0][-1], "sha256:oci-index")

    def test_missing_recovery_image_fails_closed_without_mutable_tag_fallback(self):
        with tempfile.TemporaryDirectory() as folder:
            update = FakeUpdate(folder)
            update.run = Mock(side_effect=["sha256:removed", RuntimeError("missing"), "sha256:removed-manifest", RuntimeError("missing")])
            with self.assertRaises(RuntimeError):
                update.recovery_image("container")
            self.assertEqual(update.run.call_count, 4)

    def test_addressable_container_image_is_preferred_over_manifest_label(self):
        with tempfile.TemporaryDirectory() as folder:
            update = FakeUpdate(folder)
            update.run = Mock(side_effect=["sha256:config", "sha256:config"])
            self.assertEqual(update.recovery_image("container"), "sha256:config")
            self.assertEqual(update.run.call_count, 2)

    def test_preflight_and_backup_failure_never_build_or_switch(self):
        for failure in ["preflight", "backup:create"]:
            with tempfile.TemporaryDirectory() as folder:
                update = FakeUpdate(folder, failure)
                with self.assertRaises(RuntimeError):
                    update.execute()
                self.assertFalse(any(" up " in c or " build " in c for c in update.commands))

    def test_build_failure_leaves_old_application_online(self):
        with tempfile.TemporaryDirectory() as folder:
            update = FakeUpdate(folder, " build ")
            with self.assertRaises(RuntimeError):
                update.execute()
            self.assertFalse(any(" down" in c or " up " in c for c in update.commands))

    def test_success_orders_migrations_and_switch_never_recreates_db(self):
        with tempfile.TemporaryDirectory() as folder:
            update = FakeUpdate(folder)
            update.execute()
            commands = update.commands
            self.assertLess(next(i for i, c in enumerate(commands) if "backup:create" in c), next(i for i, c in enumerate(commands) if " build " in c))
            self.assertIn("migration_status", commands)
            self.assertIn("health_passed", commands)
            for command in commands:
                if " up " in command:
                    self.assertIn("--no-deps", command)
                    self.assertNotIn(" db", command)
            self.assertTrue(any("queue:restart" in c for c in commands))

    def test_http_failure_rolls_back_images_but_not_database_and_leaves_workers_stopped(self):
        with tempfile.TemporaryDirectory() as folder:
            update = FakeUpdate(folder, "http")
            with self.assertRaises(RuntimeError):
                update.execute()
            self.assertIn("rollback_images_completed", update.commands)
            self.assertFalse(any("backup:restore" in c or "migrate:rollback" in c for c in update.commands))
            self.assertTrue(any("previous-images.json" in c for c in update.commands))

    def test_dry_run_has_no_mutations_or_state_files(self):
        with tempfile.TemporaryDirectory() as folder:
            update = FakeUpdate(str(Path(folder) / "state"), dry=True)
            update.execute()
            self.assertEqual(update.commands, ["preflight", "dry_run_passed"])
            self.assertFalse((Path(folder) / "state").exists())

    def test_rollback_can_recover_when_new_laravel_cannot_boot(self):
        with tempfile.TemporaryDirectory() as folder:
            update = FakeUpdate(folder, "artisan down")
            update.run_dir = Path(folder)
            update.previous = {s: "sha256:old" for s in update.services}
            update.rollback_images()
            self.assertTrue(any("storage/framework/down" in c for c in update.commands))
            self.assertIn("rollback_images_completed", update.commands)
            stop_web = next(i for i, c in enumerate(update.commands) if "stop web" in c)
            switch = next(i for i, c in enumerate(update.commands) if " up " in c)
            self.assertLess(stop_web, switch)


if __name__ == "__main__":
    unittest.main()
