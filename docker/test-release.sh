#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
env_file=$(mktemp)
project="dispatcher-test-$$"
cleanup() {
    docker compose --env-file "$env_file" -p "$project" down -v || true
    unlink "$env_file"
}
trap cleanup EXIT
{
    printf 'DB_PASS=%s\n' "$(openssl rand -hex 16)"
    printf 'DISPATCHER_API_TOKEN=%s\n' "$(openssl rand -hex 32)"
    for role in ADMIN DISPATCHER ENGINEER; do
        printf 'RELEASE_%s_PASSWORD=%s\n' "$role" "$(openssl rand -hex 16)"
    done
    printf 'WEB_PORT=%s\n' "${TEST_PORT:-18091}"
} >"$env_file"
set -a
. "$env_file"
set +a
docker compose --env-file "$env_file" -p "$project" up -d --build
export TEST_BASE_URL="http://127.0.0.1:$WEB_PORT"
for _ in $(seq 1 45); do
    if curl -fsS "$TEST_BASE_URL/login" >/dev/null; then break; fi
    sleep 2
done
python3 tests/smoke/release.py
