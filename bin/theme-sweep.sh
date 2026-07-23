#!/usr/bin/env bash
set -euo pipefail

FILES=$(find templates -name '*.html.twig')

sweep() {
    perl -0777 -pi -e "s{(?<![\\w:./-])$1}{$2}g" $FILES
}

# ── 1. Hover text (before base text rules) ──────────────────────────────────
sweep 'hover:text-gray-800\s+dark:hover:text-gray-100'   'hover:text-ink'
sweep 'hover:text-gray-700\s+dark:hover:text-gray-200'   'hover:text-ink'
sweep 'hover:text-zinc-700\s+dark:hover:text-zinc-200'   'hover:text-ink'
sweep 'hover:text-gray-600\s+dark:hover:text-gray-300'   'hover:text-ink-soft'
sweep 'hover:text-zinc-600\s+dark:hover:text-zinc-400'   'hover:text-ink-soft'

# ── 2. Base text ────────────────────────────────────────────────────────────
sweep 'text-gray-900\s+dark:text-gray-100'   'text-ink'
sweep 'text-zinc-900\s+dark:text-zinc-100'   'text-ink'
sweep 'text-gray-700\s+dark:text-gray-300'   'text-ink-soft'
sweep 'text-gray-700\s+dark:text-gray-200'   'text-ink-soft'
sweep 'text-zinc-700\s+dark:text-zinc-300'   'text-ink-soft'
sweep 'text-zinc-700\s+dark:text-zinc-200'   'text-ink-soft'
sweep 'text-zinc-600\s+dark:text-zinc-300'   'text-ink-soft'
sweep 'text-gray-600\s+dark:text-gray-400'   'text-ink-muted'
sweep 'text-gray-600\s+dark:text-gray-300'   'text-ink-soft'
sweep 'text-gray-300\s+dark:text-gray-600'   'text-ink-faint'
sweep 'text-gray-300\s+dark:text-gray-700'   'text-ink-faint'
sweep 'text-zinc-300\s+dark:text-zinc-600'   'text-ink-faint'
sweep 'placeholder-gray-400\s+dark:placeholder-gray-500'    'placeholder:text-ink-faint'
sweep 'placeholder-gray-400\s+dark:placeholder-gray-600'    'placeholder:text-ink-faint'
sweep 'placeholder:text-zinc-400\s+dark:placeholder:text-zinc-500' 'placeholder:text-ink-faint'

# ── 3. Borders & dividers ───────────────────────────────────────────────────
sweep 'border-gray-100/70\s+dark:border-gray-800/70'   'border-line'
sweep 'border-gray-200/60\s+dark:border-white/10'      'border-line'
sweep 'border-gray-200\s+dark:border-gray-700'         'border-line'
sweep 'border-gray-200\s+dark:border-gray-800'         'border-line'
sweep 'border-gray-100\s+dark:border-gray-800'         'border-line'
sweep 'border-black/\[0\.04\]\s+dark:border-white/5'   'border-line'
sweep 'border-white/10\s+dark:border-white/\[0\.06\]'  'border-line'
sweep 'border-zinc-300/40\s+dark:border-zinc-600/30'   'border-field'
sweep 'divide-gray-100/70\s+dark:divide-gray-800/70'   'divide-line'
sweep 'divide-black/\[0\.04\]\s+dark:divide-white/5'   'divide-line'

# ── 4. Surfaces ─────────────────────────────────────────────────────────────
sweep 'hover:bg-gray-200\s+dark:hover:bg-white/10'   'hover:bg-hover'
sweep 'hover:bg-gray-200\s+dark:hover:bg-gray-700'   'hover:bg-hover'
sweep 'hover:bg-gray-50\s+dark:hover:bg-gray-800'    'hover:bg-hover'
sweep 'hover:bg-white/20\s+dark:hover:bg-white/10'   'hover:bg-hover'
sweep 'hover:bg-black/10\s+dark:hover:bg-white/10'   'hover:bg-hover'
sweep 'hover:bg-black/5\s+dark:hover:bg-white/10'    'hover:bg-hover'
sweep 'bg-gray-100\s+dark:bg-gray-800'               'bg-sunken'
sweep 'bg-gray-100\s+dark:bg-white/10'               'bg-sunken'
sweep 'bg-gray-100\s+dark:bg-white/5'                'bg-sunken'
sweep 'bg-zinc-100\s+dark:bg-zinc-800'               'bg-sunken'
sweep 'bg-white/10\s+dark:bg-white/5'                'bg-sunken'
sweep 'bg-white/60\s+dark:bg-zinc-800/40'            'bg-field'
sweep 'bg-white\s+dark:bg-zinc-800(?![/\w-])'        'bg-field'
sweep 'bg-white/60\s+dark:bg-gray-900/40'            'bg-pane-soft'
sweep 'bg-white/95\s+dark:bg-gray-800/95'            'bg-pane-soft'

# ── 5. Accent / semantic state ──────────────────────────────────────────────
sweep 'text-blue-600\s+dark:text-blue-400'    'text-accent'
sweep 'text-blue-600\s+dark:text-blue-300'    'text-accent'
sweep 'text-red-600\s+dark:text-red-400'      'text-danger'
sweep 'text-red-600\s+dark:text-red-300'      'text-danger'
sweep 'text-red-700\s+dark:text-red-400'      'text-danger'
sweep 'text-red-700\s+dark:text-red-300'      'text-danger'
sweep 'text-red-500\s+dark:text-red-400'      'text-danger'
sweep 'hover:text-red-600\s+dark:hover:text-red-400' 'hover:text-danger'
sweep 'text-amber-600\s+dark:text-amber-400'  'text-warning'

echo "Done. Now: git diff"
