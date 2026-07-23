#!/usr/bin/env bash
set -euo pipefail

FILES=$(find templates -name '*.html.twig')

sweep() {
    perl -0777 -pi -e "s{(?<![\\w:./-])$1}{$2}g" $FILES
}
sweep 'bg-zinc-100\s+hover:bg-zinc-200\s+dark:bg-zinc-800\s+dark:hover:bg-zinc-700' 'btn-quiet'
sweep 'btn-quiet\s+text-ink-soft' 'btn-quiet'
sweep 'bg-zinc-100\s+hover:bg-red-100\s+dark:bg-zinc-800\s+dark:hover:bg-red-900/40' 'btn-quiet hover:bg-danger-soft'
sweep 'border-black/\[0\.08\]\s+dark:border-white/10' 'border-line'

echo "Done. Now: git diff"
