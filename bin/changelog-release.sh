#!/bin/sh
# The `## vX.Y.Z — date` section of a release, written into CHANGELOG.md from
# changelog.d/: bin/changelog-release.sh v0.3.8 [2026-10-22]
#
# WHY THIS EXISTS
# ───────────────
# Every pull request used to add its entry to `## Unreleased` in CHANGELOG.md,
# at the same place in the same file, so the second of any two pull requests
# conflicted with the first once that one merged. Fixing the conflict is a
# push, a push re-runs the whole E2E suite, and a batch of four bug fixes for
# one milestone waited on four suite runs in a row to settle nothing but the
# order of paragraphs. Now each pull request adds a file of its own under
# changelog.d/, no two touch the same file, and this script puts them together
# once, in the `Release vX.Y.Z` pull request (CONTRIBUTING.md → Releasing).
#
# Why a script at release time and not a reader that merges the files at run
# time: CHANGELOG.md stays the one file everything reads — the What's new
# dialog (ChangelogReader), bin/release-notes.sh and the GitHub page — and
# none of them has to learn about a second place. Entries are only shown once
# they are released, and by then they are in the file.
#
# What goes into the section, in this order:
#
#   1. fragments with a <!-- highlight --> marker, by file name;
#   2. the other fragments, by file name;
#   3. whatever is still written under `## Unreleased` in CHANGELOG.md, last.
#
# The third is kept on purpose. Pull requests opened before changelog.d/
# existed write there, and `### Before you upgrade` is a section of its own
# that a fragment cannot be (see changelog.d/README.md): a heading in the
# middle would claim every fragment after it. Last, so it claims nothing.
#
# Fragments are deleted once they are in. Exits non-zero, changing nothing,
# when the version already has a section or there is nothing to release.

set -eu

tag="${1:?usage: bin/changelog-release.sh vX.Y.Z [YYYY-MM-DD]}"
date="${2:-$(date +%Y-%m-%d)}"
root="${CHANGELOG_ROOT:-.}"
changelog="$root/CHANGELOG.md"
fragments="$root/changelog.d"

case "$tag" in
    v[0-9]*.[0-9]*.[0-9]*) ;;
    *) echo "Not a release tag: $tag (expected vX.Y.Z)" >&2; exit 1 ;;
esac

if grep -q "^## $tag\( \|\$\)" "$changelog"; then
    echo "$changelog already has a \"## $tag\" section" >&2
    exit 1
fi

# Every fragment but the README, highlights first, each group by file name.
# `sort` on the name alone: they are prefixed with the issue number, and the
# order inside a release is the order they were asked for, near enough.
files=$(find "$fragments" -maxdepth 1 -type f -name '*.md' ! -name 'README.md' 2>/dev/null | sort)
highlighted=""
plain=""

for file in $files; do
    if grep -q '<!--[[:space:]]*highlight' "$file"; then
        highlighted="$highlighted $file"
    else
        plain="$plain $file"
    fi
done

# One fragment's text without its leading and trailing blank lines, then one
# blank line: the paragraphs of the section are separated by exactly one.
emit() {
    sed -e '/./,$!d' "$1" | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}'
    echo
}

# The `## Unreleased` section's text, if there is one, trimmed the same way.
unreleased=$(awk '
    /^## / { inside = ($0 ~ /^## Unreleased[[:space:]]*$/); next }
    inside { print }
' "$changelog" | sed -e '/./,$!d' | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}')

section=$(
    for file in $highlighted $plain; do
        emit "$file"
    done

    if [ -n "$unreleased" ]; then
        printf '%s\n\n' "$unreleased"
    fi
)

if [ -z "$section" ]; then
    echo "Nothing to release: no fragments in $fragments and no ## Unreleased text" >&2
    exit 1
fi

# The new file: everything before the first `## ` heading, the release, then
# every older section with `## Unreleased` left out — its text is in the
# release now.
out="$changelog.tmp.$$"

{
    awk '/^## /{ exit } { print }' "$changelog"
    printf '## %s — %s\n\n%s\n\n' "$tag" "$date" "$section"
    awk '
        /^## / { seen = 1; skip = ($0 ~ /^## Unreleased[[:space:]]*$/) }
        seen && !skip { print }
    ' "$changelog"
} > "$out"

mv "$out" "$changelog"

for file in $highlighted $plain; do
    rm "$file"
done

echo "Wrote ## $tag — $date with $(echo $highlighted $plain | wc -w | tr -d ' ') fragment(s)"
