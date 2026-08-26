#!/bin/sh
#
# Deploy an update to a demo instance, and leave the disk where it found it.
#
# WHY THIS IS A SCRIPT AND NOT THREE COMMANDS IN A HANDBOOK
# ─────────────────────────────────────────────────────────
# Because one of the three fails silently.
#
# `docker compose pull` can run out of disk part-way through extracting a
# layer. `up -d --wait` then brings the stack up on the image that is ALREADY
# there and reports every container Healthy — so a deploy that changed nothing
# looks exactly like one that worked, and the only evidence is a line buried in
# the middle of the pull output. That is not hypothetical: v0.1.36 was believed
# deployed for twenty minutes while the demo went on serving v0.1.35.
#
# Piping the pull anywhere makes it worse, because the exit status then belongs
# to whatever came after the pipe. `pull | tail -2` reports success no matter
# what the pull did. Nothing here is piped.
#
# And the disk fills on its own: every deploy leaves its predecessor behind and
# nothing collects them. Eleven orphaned images is what filled it the first
# time — on a shared host that also runs Apache, MySQL and ClamAV, so only a
# few GB are ever spare.
#
# So: check there is room, pull and STOP if that fails, bring the stack up,
# then take the old images away.
#
# USAGE
#   cd /opt/plmail-demo && ./deploy-demo.sh
#
#   DEMO_COMPOSE_FILES   overlays, if the defaults are wrong
#   DEMO_MIN_FREE_MB     refuse to start below this much free (default 3000)
set -eu

MIN_FREE_MB="${DEMO_MIN_FREE_MB:-3000}"

# The repo's two, plus the host's own port-mapping overlay when it is there.
# compose.proxy.yaml is not in the repository — it belongs to a host that
# already has something on port 80 — so it is included by existence rather
# than by name in the default.
if [ -n "${DEMO_COMPOSE_FILES:-}" ]; then
    FILES="$DEMO_COMPOSE_FILES"
else
    FILES="-f compose.yaml -f compose.demo.yaml"

    if [ -f compose.proxy.yaml ]; then
        FILES="$FILES -f compose.proxy.yaml"
    fi
fi

if [ ! -f compose.yaml ]; then
    echo "deploy-demo: no compose.yaml here — run this from the demo's directory." >&2
    exit 1
fi

# ── 1. Room to land in ───────────────────────────────────────────────────────
# Checked BEFORE the pull rather than diagnosed after it, because the failure
# mode a full disk produces is a cryptic extraction error followed by a
# perfectly healthy stack running yesterday's code.
FREE_MB=$(df -Pm . | awk 'NR==2 {print $4}')

if [ "$FREE_MB" -lt "$MIN_FREE_MB" ]; then
    echo "deploy-demo: only ${FREE_MB}MB free, want ${MIN_FREE_MB}MB." >&2
    echo "deploy-demo: reclaiming what the last deploys left behind first." >&2

    docker image prune -af

    FREE_MB=$(df -Pm . | awk 'NR==2 {print $4}')

    if [ "$FREE_MB" -lt "$MIN_FREE_MB" ]; then
        echo "deploy-demo: still only ${FREE_MB}MB free. Not pulling — a pull that" >&2
        echo "deploy-demo: runs out mid-layer leaves the stack up on the OLD image" >&2
        echo "deploy-demo: and reports success. Free some space and run this again." >&2
        exit 1
    fi
fi

BEFORE=$(docker compose $FILES images -q php 2>/dev/null | head -1 || true)

# ── 2. Pull, and stop here if it fails ───────────────────────────────────────
echo "deploy-demo: pulling (${FREE_MB}MB free)…"
docker compose $FILES pull

# ── 3. Up ────────────────────────────────────────────────────────────────────
echo "deploy-demo: bringing the stack up…"
docker compose $FILES up -d --wait

# ── 4. Take the old images away ──────────────────────────────────────────────
# -a, not just dangling: the previous release is a fully tagged image that
# nothing references any more, and dangling-only leaves every one of them.
#
# NEVER --volumes. The demo's database lives in one.
echo "deploy-demo: pruning images the deploy replaced…"
docker image prune -af

AFTER=$(docker compose $FILES images -q php 2>/dev/null | head -1 || true)

echo
if [ "$BEFORE" = "$AFTER" ]; then
    echo "deploy-demo: done — image unchanged (${AFTER:-unknown})."
    echo "deploy-demo: that is expected when already current, and a red flag if not."
else
    echo "deploy-demo: done — ${BEFORE:-none} -> ${AFTER:-unknown}"
fi

echo "deploy-demo: $(df -Ph . | awk 'NR==2 {print $4}') free."
