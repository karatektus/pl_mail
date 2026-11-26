#!/usr/bin/env bash
# Runs on the QA host inside qa-exec's disposable checkout of the commit under test.
set -uo pipefail

head=$PWD
skill=.agents/skills/verify-plmail
ev=${QA_EVIDENCE_DIR:?drive.sh runs under qa-exec; start it with $skill/verify}
cache=$HOME/.cache/plmail-verify
mkdir -p "$cache/composer"
: >"$ev/summary.txt"

DEMO_EMAIL=demo@plmail.test
DEMO_PASSWORD=demo-password-not-secret

if docker info >/dev/null 2>&1; then
  SUDO=
elif sudo -n docker info >/dev/null 2>&1; then
  SUDO="sudo -n"
else
  echo "FAIL  Docker: neither docker nor sudo -n docker reaches the daemon" | tee "$ev/doctor.txt"
  echo "2 doctor.txt" >>"$ev/summary.txt"
  exit 2
fi

record() { echo "$1 $2" >>"$ev/summary.txt"; }

free_port() {
  local port
  for port in $(seq 8100 8199); do
    ss -Htln "sport = :$port" | grep -q . || { echo "$port"; return; }
  done
  return 1
}

compose_for() {
  local side=$1 tree=$2 port=$3
  echo "$SUDO env TEST_HTTP_PORT=$port docker compose -p plmail-verify-$side-$$ -f $tree/compose.test.yaml"
}

teardown() {
  local compose=$1
  $compose run --rm --no-deps --entrypoint chown app -R "$(id -u):$(id -g)" /app >/dev/null 2>&1
  $compose down -v --remove-orphans >/dev/null 2>&1
}

stacks=()
cleanup() {
  local compose
  for compose in "${stacks[@]}"; do teardown "$compose"; done
  stacks=()
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

# Starts the test stack for one tree, seeds the demo mailbox and checks the instance.
# Sets PORT and COMPOSE for drive.
serve() {
  local side=$1 tree=$2 out=$ev/$1 port compose
  mkdir -p "$out" "$tree/var/log" "$tree/public/assets"
  port=$(free_port) || { echo "FAIL  no free port in 8100-8199" >"$out/doctor.txt"; return 1; }
  compose=$(compose_for "$side" "$tree" "$port")
  stacks+=("$compose")
  echo "$compose" >"$out/compose.txt"

  $compose build app >"$out/build.log" 2>&1 || { record $? "$side/build.log"; return 1; }
  $compose run --rm --no-deps --volume "$cache/composer:/composer-cache" --env COMPOSER_CACHE_DIR=/composer-cache \
    --entrypoint docker-php-entrypoint app composer install --prefer-dist --no-progress --no-interaction \
    >"$out/composer.log" 2>&1 || { record $? "$side/composer.log"; return 1; }
  $compose up -d --wait app worker-bulk >"$out/up.log" 2>&1 || {
    local code=$?
    $compose logs --no-color >>"$out/up.log" 2>&1
    record "$code" "$side/up.log"
    return 1
  }
  {
    $compose exec -T app php bin/console app:test:seed-user --email="$DEMO_EMAIL" --password="$DEMO_PASSWORD" &&
      $compose exec -T app php bin/console app:test:seed-demo --email="$DEMO_EMAIL"
  } >"$out/seed.log" 2>&1 || { record $? "$side/seed.log"; return 1; }

  local status bad=0
  {
    echo "info  $(git -C "$tree" rev-parse HEAD) on port $port"
    if $compose ps --status running --services | grep -qx app; then echo "ok    app container running"; else echo "FAIL  app container not running"; bad=1; fi
    status=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/login")
    if [ "$status" = 200 ]; then echo "ok    /login answers 200"; else echo "FAIL  /login answers $status"; bad=1; fi
    if grep -q "$DEMO_EMAIL" "$out/seed.log"; then echo "ok    demo mailbox seeded for $DEMO_EMAIL"; else echo "FAIL  seed log never names $DEMO_EMAIL"; bad=1; fi
  } >"$out/doctor.txt"
  [ "$bad" = 0 ] || { record 1 "$side/doctor.txt"; return 1; }
  PORT=$port COMPOSE=$compose
}

drive() {
  local side=$1 port=$2 compose=$3 n=0 code line dir
  local console="$compose exec -T app php bin/console"
  run() {
    local label=$1
    shift
    n=$((n + 1))
    dir=$ev/$side/$(printf '%02d' "$n")-$label
    mkdir -p "$dir"
    printf '%q ' "$@" >"$dir/command.txt"
    TEST_HTTP_PORT=$port E2E_COMPOSE="$compose" E2E_CONSOLE="$console" VERIFY_OUT=$dir/test-results \
      VERIFY_DEMO_EMAIL=$DEMO_EMAIL VERIFY_DEMO_PASSWORD=$DEMO_PASSWORD "$@" >"$dir/output.log" 2>&1
    code=$?
    echo "$code" >"$dir/exit-code"
    record "$code" "${dir#"$ev"/}"
  }
  cd "$head" || return 1
  [ -z "${VERIFY_PATHS//$'\n'/}" ] || run pages npx playwright test -c "$skill/playwright.config.ts" "$skill/pages.spec.ts"
  while IFS= read -r line; do
    [ -z "$line" ] || run "spec-$(basename "$line" .spec.ts)" npx playwright test -c "$skill/playwright.config.ts" "$line"
  done <<<"$VERIFY_SPECS"
  while IFS= read -r line; do
    [ -z "$line" ] || eval "run e2e npx playwright test -c playwright.config.ts --workers=1 --output=\"\$ev/$side/e2e-results-$n\" $line"
  done <<<"$VERIFY_E2E"
}

if [ -n "${VERIFY_SPECS_TAR:-}" ]; then
  base64 -d <<<"$VERIFY_SPECS_TAR" | tar -xzf - -C "$head"
  while IFS= read -r line; do [ -z "$line" ] || cp "$line" "$ev/"; done <<<"$VERIFY_SPECS"
fi

npm ci --no-audit --no-fund >"$ev/npm.log" 2>&1 || { record $? npm.log; exit 1; }
npx playwright install chromium >"$ev/playwright-install.log" 2>&1 || { record $? playwright-install.log; exit 1; }

overall=0
sides=()
[ -z "${QA_BASE_TREE:-}" ] || sides+=("before:$QA_BASE_TREE")
sides+=("after:$head")
for pair in "${sides[@]}"; do
  side=${pair%%:*}
  tree=${pair#*:}
  if serve "$side" "$tree"; then
    drive "$side" "$PORT" "$COMPOSE"
  else
    overall=1
  fi
  cleanup
done

while read -r code _; do [ "$code" = 0 ] || overall=1; done <"$ev/summary.txt"
exit "$overall"
