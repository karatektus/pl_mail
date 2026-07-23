#!/usr/bin/env bash
set -euo pipefail

FILES=$(find templates -name '*.html.twig')

# The lookbehind stops a pattern from matching the tail of a longer token
# (e.g. 'bg-blue-500/10' matching inside 'dark:bg-blue-500/10').
sweep() {
    perl -0777 -pi -e "s{(?<![\\w:./-])$1}{$2}g" $FILES
}

# ── 1. Composite clusters (longest first) ───────────────────────────────────
sweep 'rounded-2xl\s+border\s+border-white/60\s+dark:border-white/10\s+bg-white/70\s+dark:bg-gray-900/60\s+backdrop-blur-xl\s+shadow-lg\s+shadow-black/5\s+dark:shadow-black/30' 'pane'
sweep 'border\s+border-white/60\s+dark:border-white/10\s+bg-white/(?:70|80)\s+dark:bg-gray-900/(?:60|80)\s+backdrop-blur-xl' 'pane-flat'
sweep 'bg-white/(?:70|80)\s+dark:bg-gray-900/(?:60|80)\s+backdrop-blur-xl' 'pane-flat'
sweep 'bg-gradient-to-br\s+from-blue-50\s+via-slate-100\s+to-indigo-100\s+dark:from-slate-950\s+dark:via-slate-900\s+dark:to-indigo-950' 'app-bg'

# ── 2. Borders & dividers ───────────────────────────────────────────────────
sweep 'border-black/\[0\.06\]\s+dark:border-white/10'          'border-line'
sweep 'border-black/\[0\.06\]\s+dark:border-white/\[0\.06\]'   'border-line'
sweep 'divide-black/\[0\.06\]\s+dark:divide-white/\[0\.06\]'   'divide-line'
sweep 'border-white/60\s+dark:border-white/10'                 'border-line'

# ── 3. Text ─────────────────────────────────────────────────────────────────
sweep 'text-zinc-800\s+dark:text-zinc-100'   'text-ink'
sweep 'text-gray-800\s+dark:text-gray-100'   'text-ink'
sweep 'text-zinc-500\s+dark:text-zinc-400'   'text-ink-muted'
sweep 'text-gray-500\s+dark:text-gray-400'   'text-ink-muted'
sweep 'text-zinc-400\s+dark:text-zinc-500'   'text-ink-faint'
sweep 'text-gray-400\s+dark:text-gray-600'   'text-ink-faint'
sweep 'text-gray-400\s+dark:text-gray-500'   'text-ink-faint'

# ── 4. Surfaces & hovers ────────────────────────────────────────────────────
sweep 'bg-white\s+dark:bg-gray-900(?![/\w-])'                      'bg-surface'
sweep 'bg-black/\[0\.04\]\s+dark:bg-white/\[0\.06\]'               'bg-raised'
sweep 'bg-black/\[0\.04\]\s+dark:bg-white/10'                      'bg-raised'
sweep 'hover:bg-black/\[0\.03\]\s+dark:hover:bg-white/\[0\.04\]'   'hover:bg-hover'
sweep 'hover:bg-black/\[0\.03\]\s+dark:hover:bg-white/5'           'hover:bg-hover'
sweep 'hover:bg-gray-100\s+dark:hover:bg-gray-800'                 'hover:bg-hover'

# ── 5. Accent ───────────────────────────────────────────────────────────────
sweep 'bg-blue-600\s+hover:bg-blue-700'                      'bg-accent hover:bg-accent-strong'
sweep 'bg-blue-500/10\s+text-blue-600\s+dark:text-blue-300'  'bg-accent-soft text-accent'

# ── 6. Radius ───────────────────────────────────────────────────────────────
sweep 'rounded-2xl(?![\w-])' 'rounded-pane'

echo "Done. Now: git diff"
