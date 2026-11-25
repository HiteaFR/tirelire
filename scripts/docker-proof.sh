#!/bin/sh
# Proof of the Tirelire Docker image, run by .github/workflows/release.yml on each platform of a
# release candidate, or by hand on a TEST Docker machine from a copy of the repository:
#
#   sh scripts/docker-proof.sh            # or TIRELIRE_PROOF_PORT=18190 sh scripts/docker-proof.sh
#
# Variables (all optional):
#   TIRELIRE_PROOF_PORT      test port (default 18180), must be free
#   TIRELIRE_PROOF_IMAGE     image already built or pulled (a release candidate pulled by digest):
#                            tagged with the unique name of the run instead of being built
#   TIRELIRE_PROOF_PLATFORM  linux/amd64 or linux/arm64: platform the container runs on (another
#                            architecture needs QEMU), checked inside the running container
#   TIRELIRE_PROOF_WAIT      seconds allowed for each healthy state (default 120; more under QEMU)
#   TIRELIRE_PROOF_VERSION   version (x.y.z) that the image label org.opencontainers.image.version
#                            must carry
#   TIRELIRE_PROOF_REVISION  commit that the image label org.opencontainers.image.revision must carry
#
# Touches NO existing container, volume, network or image: compose project with a unique name
# (tirelire-proof-<date>-<pid>), image with a unique tag, refusal if any of them already exists,
# free test port required; the compose file is read from a throw-away copy (git archive: no
# untracked file enters the build context), removed at the end with the project's volume and
# image only, even on failure. Base images and build cache stay on the machine.
#
# Steps (each one shown "ok" or "FAILED", stop at the first failure):
#   1. image build, or the given image tagged with the unique name (labels checked);
#   2. first start: key generated in the volume, migrations, generic categories, Docker
#      healthcheck "healthy", /up 200, / redirects to /login, /login renders the form;
#   3. user created from the command line (password through a variable, never as an argument);
#   4. permissions: process uid 10001, data directory and database owned by it, key 0600, code
#      not writable by the application;
#   5. restart: database, user, key and categories kept, healthy again;
#   6. stop and cleanup.
set -eu
unset COMPOSE_FILE COMPOSE_PROFILES COMPOSE_PROJECT_NAME
[ $# -eq 0 ] || { sed -n 2,32p "$0"; exit 2; }

SRC=$(cd "$(dirname "$0")/.." && pwd)
PORT=${TIRELIRE_PROOF_PORT:-18180}
case $PORT in '' | *[!0-9]*) echo "TIRELIRE_PROOF_PORT: number expected" >&2; exit 2 ;; esac
WAIT=${TIRELIRE_PROOF_WAIT:-120}
case $WAIT in '' | *[!0-9]*) echo "TIRELIRE_PROOF_WAIT: number of seconds expected" >&2; exit 2 ;; esac
[ "$WAIT" -ge 40 ] || { echo "TIRELIRE_PROOF_WAIT: 40 seconds at least (first healthcheck after 30 s)" >&2; exit 2; }
GIVEN=${TIRELIRE_PROOF_IMAGE:-}
PLATFORM=${TIRELIRE_PROOF_PLATFORM:-}
case $PLATFORM in '' | linux/amd64 | linux/arm64) ;; *) echo "TIRELIRE_PROOF_PLATFORM: linux/amd64 or linux/arm64 expected" >&2; exit 2 ;; esac
VERSION=${TIRELIRE_PROOF_VERSION:-}
case $VERSION in '') ;; *[!0-9.]* | .* | *. | *..*) echo "TIRELIRE_PROOF_VERSION: x.y.z expected" >&2; exit 2 ;; esac
REVISION=${TIRELIRE_PROOF_REVISION:-}
PROJECT=tirelire-proof-$(date +%Y%m%d%H%M%S)-$$
IMAGE=$PROJECT:local

ok() { printf 'ok   %s\n' "$*"; }
ko() { printf 'FAILED %s\n' "$*" >&2; exit 1; }

for t in docker git curl tar ss; do command -v "$t" >/dev/null || ko "missing tool: $t"; done
docker compose version >/dev/null 2>&1 || ko "docker compose (v2) missing"

[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$PROJECT")" ] || ko "containers of project $PROJECT already exist"
[ -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT")" ] || ko "volumes of project $PROJECT already exist"
! docker image inspect "$IMAGE" >/dev/null 2>&1 || ko "image $IMAGE already exists"
[ -z "$GIVEN" ] || docker image inspect "$GIVEN" >/dev/null 2>&1 || ko "image $GIVEN (TIRELIRE_PROOF_IMAGE) not found on this machine"
! ss -Hltn "sport = :$PORT" | grep -q . || ko "port $PORT already open on this machine (TIRELIRE_PROOF_PORT)"

# unique names exported BEFORE the compose file is read: it is never used with its default names
export TIRELIRE_IMAGE="$IMAGE" TIRELIRE_PORT="$PORT" APP_URL="http://127.0.0.1:$PORT"
[ -z "$PLATFORM" ] || export DOCKER_DEFAULT_PLATFORM="$PLATFORM"
WORK=$(mktemp -d)
cleanup() {
  rc=$?
  # down only if the compose image is EXACTLY the unique tag of this run
  if [ -f "$WORK/src/compose.yaml" ] && [ "${TIRELIRE_IMAGE:-}" = "$IMAGE" ]; then
    docker compose -f "$WORK/src/compose.yaml" -p "$PROJECT" down -v --rmi all --remove-orphans >/dev/null 2>&1 || true
  fi
  rm -rf "$WORK"
  [ "$rc" -eq 0 ] && echo "cleanup done (project $PROJECT)" || echo "cleanup done (project $PROJECT) after a failure, code $rc" >&2
}
trap cleanup EXIT
trap 'exit 130' INT TERM

mkdir "$WORK/src"
git -C "$SRC" archive HEAD | tar -x -C "$WORK/src"
dc() { docker compose -f "$WORK/src/compose.yaml" -p "$PROJECT" "$@"; }
url=http://127.0.0.1:$PORT

wait_health() {
  i=0
  while [ $i -lt $((WAIT / 2)) ]; do
    st=$(docker inspect --format '{{.State.Health.Status}}' "$(dc ps -q app)" 2>/dev/null || echo absent)
    [ "$st" = healthy ] && return 0
    i=$((i + 1))
    sleep 2
  done
  dc logs --no-color app | tail -n 30 >&2
  return 1
}
in_app() { dc exec -T app "$@"; }
# a count in the database, through PHP (the image has no sqlite3 client)
count() {
  in_app php -r '$db = new PDO("sqlite:/var/lib/tirelire/database.sqlite"); echo $db->query("select count(*) from '"$1"'")->fetchColumn();'
}
label() { docker image inspect --format "{{ index .Config.Labels \"$1\" }}" "$IMAGE"; }

# 1. build, or the given image under the unique name (removed with the project at cleanup)
if [ -n "$GIVEN" ]; then
  docker tag "$GIVEN" "$IMAGE" || ko "1. tag of $GIVEN"
  ok "1. image given ($GIVEN), tagged $IMAGE"
else
  dc build app >/dev/null || ko "1. image build"
  ok "1. image built ($IMAGE)"
fi
[ -z "$VERSION" ] || [ "$(label org.opencontainers.image.version)" = "$VERSION" ] || ko "1. label org.opencontainers.image.version is not $VERSION"
[ -z "$REVISION" ] || [ "$(label org.opencontainers.image.revision)" = "$REVISION" ] || ko "1. label org.opencontainers.image.revision is not $REVISION"
[ -z "$VERSION$REVISION" ] || ok "1. labels: ${VERSION:+version $VERSION }${REVISION:+revision $REVISION}"

# 2. first start
dc up -d app >/dev/null || ko "2. start"
wait_health || ko "2. Docker healthcheck"
dc logs --no-color app 2>&1 | grep -q 'application key generated' || ko "2. no key generated on first start"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$url/up")" = 200 ] || ko "2. /up is not 200"
[ "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$url/")" = "302 $url/login" ] || ko "2. / does not redirect to /login"
curl -fsS "$url/login" | grep -q 'type="password"' || ko "2. /login without a password field"
categories=$(count categories)
[ "$categories" -gt 0 ] || ko "2. no generic category seeded"
ok "2. first start: key generated, $categories categories, healthy, /up 200, /login rendered"
if [ -n "$PLATFORM" ]; then
  case $PLATFORM in linux/amd64) want=x86_64 ;; linux/arm64) want=aarch64 ;; esac
  got=$(in_app uname -m | tr -d '\r')
  [ "$got" = "$want" ] || ko "2. container runs on $got, $want expected ($PLATFORM)"
  ok "2. container on $PLATFORM ($got)"
fi

# 3. user from the command line (test password through a variable, never as an argument)
pw=$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | cut -c1-20)Aa1
FINANCE_USER_PASSWORD=$pw dc exec -T -e FINANCE_USER_PASSWORD app php artisan finance:user proof@example.com --password-env --no-interaction >/dev/null || ko "3. finance:user"
[ "$(count users)" = 1 ] || ko "3. user not created"
ok "3. user created with finance:user"

# 4. permissions
[ "$(in_app id -u | tr -d '\r')" = 10001 ] || ko "4. not running as uid 10001"
in_app stat -c '%u %a %n' /var/lib/tirelire /var/lib/tirelire/database.sqlite /var/lib/tirelire/app.key >"$WORK/stat" || ko "4. stat"
grep -q '^10001 [0-7]* /var/lib/tirelire$' "$WORK/stat" && grep -q '^10001 [0-7]* /var/lib/tirelire/database.sqlite$' "$WORK/stat" || ko "4. data not owned by uid 10001: $(tr '\n' ';' <"$WORK/stat")"
grep -q '^10001 600 /var/lib/tirelire/app.key$' "$WORK/stat" || ko "4. app.key is not 0600"
! in_app sh -c 'touch /app/write-test' 2>/dev/null || ko "4. code writable by the application"
ok "4. uid 10001, data and key 0600 in the volume, code read only"

# 5. restart
key=$(in_app cat /var/lib/tirelire/app.key)
dc restart app >/dev/null || ko "5. restart"
wait_health || ko "5. healthcheck after restart"
[ "$(in_app cat /var/lib/tirelire/app.key)" = "$key" ] || ko "5. key changed"
[ "$(count users)" = 1 ] || ko "5. user lost"
[ "$(count categories)" = "$categories" ] || ko "5. categories seeded again"
ok "5. restart: database, user, key and categories kept, healthy"

# 6. stop (cleanup follows, trap)
dc down >/dev/null || ko "6. stop"
ok "6. stopped; proof complete"
