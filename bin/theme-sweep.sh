#!/usr/bin/env bash
set -euo pipefail

FILES=$(find templates -name '*.html.twig')

sweep() {
    perl -0777 -pi -e "s{(?<![\\w:./-])$1}{$2}g" $FILES
}

# ── Muted → strong hover pairs ──────────────────────────────────────────────
sweep 'text-zinc-300\s+hover:text-zinc-500\s+dark:text-zinc-600\s+dark:hover:text-zinc-400' 'text-ink-faint hover:text-ink-muted'
sweep 'text-ink-muted\s+hover:text-gray-800\s+dark:hover:text-white' 'text-ink-muted hover:text-ink'
sweep 'hover:text-zinc-200\s+dark:hover:text-zinc-300' 'hover:text-ink'
sweep 'hover:text-blue-600\s+dark:hover:text-blue-300' 'hover:text-accent'
sweep 'hover:text-red-500\s+dark:hover:text-red-400'   'hover:text-danger'
sweep 'text-zinc-900\s+dark:text-zinc-300'             'text-ink'

# ── Hovers ──────────────────────────────────────────────────────────────────
sweep 'hover:bg-gray-100\s+dark:hover:bg-white/10' 'hover:bg-hover'
sweep 'hover:bg-zinc-50\s+dark:hover:bg-zinc-700'  'hover:bg-hover'
sweep 'hover:bg-white/70\s+dark:hover:bg-white/10' 'hover:bg-hover'
sweep 'focus:bg-white\s+dark:focus:bg-gray-700'    'focus:bg-field'

# ── Borders ─────────────────────────────────────────────────────────────────
sweep 'border-gray-100/80\s+dark:border-white/\[0\.06\]' 'border-line'

# ── Status dots (bg-line is too faint at 0.06 — use ink-faint) ──────────────
sweep 'bg-zinc-300 dark:bg-zinc-600' 'bg-ink-faint'
sweep 'bg-gray-300 dark:bg-gray-700' 'bg-ink-faint'

# ── Toast (inverted surface) ────────────────────────────────────────────────
sweep 'bg-zinc-900\s+dark:bg-zinc-100\s+text-zinc-100\s+dark:text-zinc-900' 'bg-inverse text-inverse-ink'

# ── Search filter pill ──────────────────────────────────────────────────────
sweep 'bg-blue-50\s+dark:bg-blue-950/40\s+text-blue-700\s+dark:text-blue-300\s*\n?\s*border\s+border-blue-200/60\s+dark:border-blue-700/40' 'bg-accent-soft text-accent border border-accent/20'

# ── Avatar button ───────────────────────────────────────────────────────────
sweep 'bg-blue-600\s+hover:ring-2\s+hover:ring-blue-400\s+hover:ring-offset-1\s+dark:hover:ring-offset-gray-900' 'bg-accent hover:ring-2 hover:ring-accent/60 hover:ring-offset-1 ring-offset-surface'

echo "Done. Now: git diff"
