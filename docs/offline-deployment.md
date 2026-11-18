# Offline production deployment

Use this procedure when the production Docker daemon cannot reach Docker Hub.
The connected x86_64 laptop builds the images; production verifies and imports
them before `safe-update.py --prebuilt` is allowed to enter maintenance.

The prebuilt manifest binds every service to its local image reference,
immutable image ID, `linux/amd64` platform, OCI revision label and SVU
release/role labels. Validation covers `app`, `web`, `queue`, `scheduler`,
`baseline_queue`, and every active optional `*_queue` worker. A missing image,
different ID, wrong architecture, wrong role or release label stops preflight
before backup, maintenance or worker shutdown.

Prebuilt mode never runs `docker compose build` and passes `--pull never` to
every release-image `run`/`up`, including rollback. After validation it tags the
expected IDs with private timestamped release tags and uses an override file, so
later changes to a mutable source tag cannot alter the deployment.
`--prebuilt --dry-run` validates the complete local set without creating tags,
state directories, backups or containers.

## Build, export and checksum on the laptop

The commands build the five production services. The deployment tool still
discovers active optional workers and requires a matching manifest entry for any
such worker; both optional feature flags remain disabled for this release. The
release identity is the clean committed source revision used for every image.

```bash
cd /home/mazen/Documents/programes/svu-quality-monitor
test -z "$(git status --porcelain)"
RELEASE_ID="$(git rev-parse HEAD)"
test "$(git rev-parse "$RELEASE_ID^{commit}")" = "$RELEASE_ID"
git diff --check

RELEASE_DIR="/tmp/svu-quality-monitor-$RELEASE_ID"
BUILD_CONTEXT="$RELEASE_DIR/build-context"
mkdir -m 0700 "$RELEASE_DIR"
mkdir -m 0700 "$BUILD_CONTEXT"
git archive "$RELEASE_ID" | tar -x -C "$BUILD_CONTEXT"
cp "$BUILD_CONTEXT/.env.docker.example" "$BUILD_CONTEXT/.env"

BUILD_COMPOSE=(
  docker compose
  --project-directory "$BUILD_CONTEXT"
  -f "$BUILD_CONTEXT/compose.yaml"
  --profile '*'
)
RELEASE_SERVICES=(app web queue scheduler baseline_queue)
"${BUILD_COMPOSE[@]}" build --pull \
  --build-arg "SVU_RELEASE_ID=$RELEASE_ID" "${RELEASE_SERVICES[@]}"

"${BUILD_COMPOSE[@]}" config --format json |
python3 -c '
import json, subprocess, sys
release, destination = sys.argv[1:]
definition = json.load(sys.stdin)
services = ["app", "web", "queue", "scheduler", "baseline_queue"]
entries = {}
platform = None
for service in services:
    configured = definition["services"][service]
    image = configured.get("image") or definition["name"] + "-" + service
    inspected = json.loads(subprocess.check_output(["docker", "image", "inspect", image]))[0]
    aliases = {"x86_64": "amd64", "aarch64": "arm64"}
    current_platform = inspected["Os"] + "/" + aliases.get(inspected["Architecture"], inspected["Architecture"])
    labels = inspected.get("Config", {}).get("Labels") or {}
    expected_role = "web" if service == "web" else "application"
    assert current_platform == "linux/amd64", (service, current_platform)
    assert labels.get("org.opencontainers.image.revision") == release, service
    assert labels.get("org.svu-quality-monitor.release") == release, service
    assert labels.get("org.svu-quality-monitor.role") == expected_role, service
    platform = platform or current_platform
    entries[service] = {"image": image, "id": inspected["Id"]}
with open(destination, "w") as stream:
    json.dump({"schema": 1, "release": release, "platform": platform, "services": entries}, stream, indent=2)
    stream.write("\n")
' "$RELEASE_ID" "$RELEASE_DIR/prebuilt-images.json"

cp "$BUILD_CONTEXT/scripts/safe-update.py" "$RELEASE_DIR/safe-update.py"
rm -rf -- "$BUILD_CONTEXT"

mapfile -t RELEASE_IMAGES < <(
  python3 -c 'import json,sys; print("\n".join(item["image"] for item in json.load(open(sys.argv[1]))["services"].values()))' \
    "$RELEASE_DIR/prebuilt-images.json"
)
docker image save "${RELEASE_IMAGES[@]}" | gzip -9 > "$RELEASE_DIR/prebuilt-images.tar.gz"
chmod 0755 "$RELEASE_DIR/safe-update.py"
(
  cd "$RELEASE_DIR"
  sha256sum prebuilt-images.json prebuilt-images.tar.gz safe-update.py > SHA256SUMS
)
tar -C /tmp -czf "/tmp/svu-quality-monitor-$RELEASE_ID-bundle.tar.gz" \
  "svu-quality-monitor-$RELEASE_ID"
(
  cd /tmp
  sha256sum "svu-quality-monitor-$RELEASE_ID-bundle.tar.gz" > \
    "svu-quality-monitor-$RELEASE_ID-bundle.tar.gz.sha256"
)
```

The build context and bundled updater come only from `git archive` of the exact
release commit. Its Dockerfile and `.dockerignore` therefore match the manifest
identity and OCI labels. A synthetic `.env` is copied from the public Docker
example solely so Compose can resolve the build and is removed with the build
context before packaging.
Docker context rules also exclude `.env*` except public examples, Composer/npm
auth, SSH/AWS material, private keys, databases, SQL/archive files,
`storage/app`, backup data and update state. `docker image save` exports image
layers and tags; it does not export containers, named volumes or databases.
Inspect the manifest and `SHA256SUMS` before transfer.

## Transfer

Replace `production-host` with the reviewed SSH host:

```bash
RELEASE_ID=<new-release-commit>
scp "/tmp/svu-quality-monitor-$RELEASE_ID-bundle.tar.gz" \
  "/tmp/svu-quality-monitor-$RELEASE_ID-bundle.tar.gz.sha256" \
  production-host:/var/www/svu-quality-monitor/
```

## Verify, import and deploy on production

Verify the outer transfer, extract into a release-only directory, verify every
inner file, install the reviewed deployment tool, then load the images:

```bash
cd /var/www/svu-quality-monitor
RELEASE_ID=<new-release-commit>
sha256sum -c "svu-quality-monitor-$RELEASE_ID-bundle.tar.gz.sha256"
tar -xzf "svu-quality-monitor-$RELEASE_ID-bundle.tar.gz"
cd "svu-quality-monitor-$RELEASE_ID"
sha256sum -c SHA256SUMS
gzip -dc prebuilt-images.tar.gz | docker image load

python3 safe-update.py \
  --compose /var/www/svu-quality-monitor/compose.yaml \
  --state /var/www/svu-quality-monitor/update-state \
  --url http://127.0.0.1:8080 \
  --prebuilt "/var/www/svu-quality-monitor/svu-quality-monitor-$RELEASE_ID/prebuilt-images.json" \
  --dry-run

install -m 0755 safe-update.py /var/www/svu-quality-monitor/scripts/safe-update.py
cd /var/www/svu-quality-monitor
python3 scripts/safe-update.py \
  --compose /var/www/svu-quality-monitor/compose.yaml \
  --state /var/www/svu-quality-monitor/update-state \
  --url http://127.0.0.1:8080 \
  --prebuilt "/var/www/svu-quality-monitor/svu-quality-monitor-$RELEASE_ID/prebuilt-images.json"

docker compose -f /var/www/svu-quality-monitor/compose.yaml ps \
  app web queue scheduler baseline_queue
docker compose -f /var/www/svu-quality-monitor/compose.yaml \
  exec -T app php artisan system:smoke-check
curl --fail --silent --show-error \
  http://127.0.0.1:8080/admin/login >/dev/null
docker compose -f /var/www/svu-quality-monitor/compose.yaml \
  exec -T app php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$flags = [
    "MONITORING_FIXED_CADENCE_ENABLED" => config("monitoring.fixed_cadence.enabled"),
    "SLO_AUTO_PREPARATION_ENABLED" => config("monitoring.slo.automatic_preparation_enabled"),
];
echo json_encode($flags, JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($flags["MONITORING_FIXED_CADENCE_ENABLED"] === false
    && $flags["SLO_AUTO_PREPARATION_ENABLED"] === false ? 0 : 1);
'
```

Keep the existing production `.env`, Compose project identity and volumes in
place. Confirm `MONITORING_FIXED_CADENCE_ENABLED=false` and
`SLO_AUTO_PREPARATION_ENABLED=false` before the dry run. The bundle contains no
environment file and the import/deploy commands do not recreate `db` or any
volume. The same verified backup, graceful drain, additive migration, heartbeat,
HTTP and image-only rollback sequence used by online mode remains in force.
