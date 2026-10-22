#!/bin/sh
# The notes of one release, cut out of CHANGELOG.md: bin/release-notes.sh v0.3.7
#
# WHY THIS EXISTS
# ───────────────
# A GitHub Release is the page an RSS reader follows (releases.atom) and the
# one "Watch → Releases" notifies about; a tag alone gives neither the notes nor
# the notification (#226). The CHANGELOG stays the one place notes are written,
# and this turns its `## vX.Y.Z — date` section into the release body, so the
# two cannot disagree.
#
# Used by the `release` job in .github/workflows/docker.yml on every `v*` tag,
# and by hand to create the releases of tags that predate that job.
#
# Prints the section without its heading (the release has a title of its own),
# preceded by the image the tag published — named by its version, which stays
# true of an old release, where `latest` would not. Exits non-zero when the
# CHANGELOG has no section for the tag: a release with empty notes is a mistake
# to stop at, not a page to publish.

set -eu

tag="${1:?usage: bin/release-notes.sh vX.Y.Z}"
changelog="${2:-CHANGELOG.md}"

# Exact match on the heading, then everything up to the next `## `. Compared as
# a string, not a regex: the dots in a version are not wildcards.
notes=$(awk -v heading="## $tag" '
    /^## / {
        if (found) exit
        if (substr($0, 1, length(heading)) == heading) {
            rest = substr($0, length(heading) + 1)
            if (rest == "" || substr(rest, 1, 1) == " ") { found = 1; next }
        }
    }
    found { print }
' "$changelog")

# One line per paragraph and per list item. The CHANGELOG is wrapped at a
# hundred columns for reading in an editor, and a release body is not: GitHub
# shows every newline there as a line break, so the wrapped prose would come out
# ragged on the page and in a feed reader. Headings, the <!-- highlight -->
# markers and anything inside a code fence stay exactly as written.
notes=$(printf '%s\n' "$notes" | awk '
    function flush() { if (buf != "") { print buf; buf = "" } }
    /^```/ { flush(); print; code = !code; next }
    code { print; next }
    /^[ \t]*$/ { flush(); print ""; next }
    /^(#|<!--)/ { flush(); print; next }
    /^([-*]|[0-9]+\.) / { flush(); sub(/[ \t]+$/, ""); buf = $0; next }
    {
        sub(/^[ \t]+/, ""); sub(/[ \t]+$/, "")
        buf = (buf == "") ? $0 : buf " " $0
    }
    END { flush() }
')

# Leading and trailing blank lines carry no meaning on a release page.
notes=$(printf '%s\n' "$notes" | sed -e '/./,$!d' | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}')

if [ -z "$notes" ]; then
    echo "No \"## $tag\" section in $changelog" >&2
    exit 1
fi

version="${tag#v}"

printf 'Image: `ghcr.io/karatektus/pl_mail:%s`\n\n%s\n' "$version" "$notes"
