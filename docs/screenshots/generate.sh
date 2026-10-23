#!/usr/bin/env sh
# generate.sh — rebuilds the screenshots embedded in README.md.
#
# It builds the image, boots three throwaway containers (one per auth method),
# runs docs/screenshots/capture.mjs against them and removes the containers
# again, including when a capture fails.
#
# Usage:
#   docs/screenshots/generate.sh
#   IMAGE=fiscalhub:dev SKIP_BUILD=1 docs/screenshots/generate.sh
#
# Environment:
#   IMAGE           image tag to build or reuse   (default fiscalhub:screenshots)
#   SKIP_BUILD      set to 1 to reuse the image   (default 0)
#   APP_PORT        none-mode port                (default 8080)
#   ACCOUNT_PORT    account-mode port             (default 8081)
#   KEYCLOAK_PORT   keycloak-mode port            (default 8082)
#   CHROME_BIN      browser passed to capture.mjs
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
IMAGE=${IMAGE:-fiscalhub:screenshots}
APP_PORT=${APP_PORT:-8080}
ACCOUNT_PORT=${ACCOUNT_PORT:-8081}
KEYCLOAK_PORT=${KEYCLOAK_PORT:-8082}

APP_CONTAINER=fiscalhub-screenshots-none
ACCOUNT_CONTAINER=fiscalhub-screenshots-account
KEYCLOAK_CONTAINER=fiscalhub-screenshots-keycloak

# Removing the containers is safe even when they were never created.
cleanup() {
    docker rm -f "$APP_CONTAINER" "$ACCOUNT_CONTAINER" "$KEYCLOAK_CONTAINER" \
        >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM
cleanup

if [ "${SKIP_BUILD:-0}" != "1" ]; then
    docker build -t "$IMAGE" "$ROOT"
fi

# The credentials below only exist inside these throwaway containers. The
# keycloak instance never talks to a provider: the capture only needs the SSO
# gate login.html renders once auth-config.php reports `keycloak`.
docker run -d --name "$APP_CONTAINER" -p "$APP_PORT:8080" \
    -e AUTH_METHOD=none "$IMAGE" >/dev/null
docker run -d --name "$ACCOUNT_CONTAINER" -p "$ACCOUNT_PORT:8080" \
    -e AUTH_METHOD=account \
    -e ACCOUNT_LOGIN=demo \
    -e ACCOUNT_PASSWORD=demo-screenshots "$IMAGE" >/dev/null
docker run -d --name "$KEYCLOAK_CONTAINER" -p "$KEYCLOAK_PORT:8080" \
    -e AUTH_METHOD=keycloak \
    -e KEYCLOAK_BASE_URL=https://keycloak.invalid \
    -e KEYCLOAK_REALM=fiscalhub \
    -e KEYCLOAK_CLIENT_ID=fiscalhub \
    -e KEYCLOAK_CLIENT_SECRET=screenshots "$IMAGE" >/dev/null

wait_for() {
    url=$1
    tries=0
    while [ "$tries" -lt 60 ]; do
        # -f only: the expected startup resets should not print anything.
        if curl -fs -o /dev/null "$url"; then
            return 0
        fi
        tries=$((tries + 1))
        sleep 1
    done
    echo "timed out waiting for $url" >&2
    return 1
}

wait_for "http://127.0.0.1:$APP_PORT/login.html"
wait_for "http://127.0.0.1:$ACCOUNT_PORT/login.html"
wait_for "http://127.0.0.1:$KEYCLOAK_PORT/login.html"

SCREENSHOT_BASE_URL="http://127.0.0.1:$APP_PORT" \
SCREENSHOT_ACCOUNT_URL="http://127.0.0.1:$ACCOUNT_PORT" \
SCREENSHOT_KEYCLOAK_URL="http://127.0.0.1:$KEYCLOAK_PORT" \
SCREENSHOT_DIR="$ROOT/docs/screenshots" \
    node "$ROOT/docs/screenshots/capture.mjs"

# Chrome writes truecolor PNGs. Reducing them to an 8-bit adaptive palette keeps
# the same look at about a third of the weight, which matters because the files
# are committed and embedded in the README. Skipped when Pillow is unavailable.
if python3 -c 'import PIL' >/dev/null 2>&1; then
    python3 - "$ROOT/docs/screenshots" <<'PY'
import glob
import os
import sys
from PIL import Image

for path in glob.glob(os.path.join(sys.argv[1], '*.png')):
    image = Image.open(path).convert('RGB')
    image = image.quantize(colors=256, method=Image.MEDIANCUT)
    temporary = path + '.tmp'
    image.save(temporary, format='PNG', optimize=True)
    os.replace(temporary, path)
PY
fi
