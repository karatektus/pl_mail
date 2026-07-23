#!/usr/bin/env bash
set -euo pipefail

FILES=$(find templates -name '*.html.twig')

sweep() {
    perl -0777 -pi -e "s{(?<![\\w:./-])$1}{$2}g" $FILES
}

# ── Tooltips (run before generic bg rules) ──────────────────────────────────
sweep 'bg-gray-800\s+dark:bg-gray-100\s+text-white\s+dark:text-gray-900' 'tooltip-bubble'
sweep "before:border-\[5px\]\s+before:border-transparent\s+before:border-b-gray-800\s+dark:before:border-b-gray-100" 'before:border-[5px] before:border-transparent'

# ── Alerts ──────────────────────────────────────────────────────────────────
sweep 'bg-red-50\s+dark:bg-red-950/60\s+border\s+border-red-200\s+dark:border-red-800/70' 'alert-danger'
sweep 'bg-red-50\s+dark:bg-red-950\s+border\s+border-red-200\s+dark:border-red-800'       'alert-danger'
sweep 'bg-red-500/10\s+dark:bg-red-500/10\s*\n?\s*border\s+border-red-500/25\s+dark:border-red-500/20' 'alert-danger'
sweep 'bg-blue-500/10\s+dark:bg-blue-500/10\s*\n?\s*border\s+border-blue-500/20\s+dark:border-blue-500/15' 'alert-info'

# ── Shadows ─────────────────────────────────────────────────────────────────
sweep 'shadow-2xl\s+shadow-black/20\s+dark:shadow-black/60' 'shadow-float'
sweep 'shadow-xl\s+shadow-black/15\s+dark:shadow-black/50'  'shadow-pop'
sweep 'shadow-xl\s+shadow-black/15'                         'shadow-pop'
sweep 'shadow-lg\s+shadow-black/5\s+dark:shadow-black/30'   'shadow-pop'

# ── Ring offsets ────────────────────────────────────────────────────────────
sweep 'ring-offset-1\s+dark:hover:ring-offset-gray-900'          'ring-offset-1 ring-offset-surface'
sweep 'focus:ring-offset-2\s+dark:focus:ring-offset-gray-900'    'focus:ring-offset-2 ring-offset-surface'

# ── Remaining surfaces ──────────────────────────────────────────────────────
sweep 'bg-white/40\s+dark:bg-white/\[0\.0[23]\]'          'bg-raised'
sweep 'hover:bg-white/60\s+dark:hover:bg-white/\[0\.06\]' 'hover:bg-hover'
sweep 'hover:bg-gray-50\s+dark:hover:bg-white/\[0\.06\]'  'hover:bg-hover'
sweep 'bg-white/60\s+dark:bg-white/\[0\.03\]'             'bg-raised'
sweep 'bg-gray-50/50\s+dark:bg-gray-800/50'               'bg-sunken'
sweep 'bg-zinc-50/80\s+dark:bg-zinc-800/40'               'bg-sunken'
sweep 'bg-zinc-50\s+dark:bg-zinc-950/60'                  'bg-sunken'
sweep 'bg-white/80\s+dark:bg-zinc-800/80'                 'bg-field'
sweep 'bg-white/50\s+dark:bg-white/5'                     'bg-sunken'
sweep 'bg-gray-100/80\s+dark:bg-gray-800/60'              'bg-sunken'
sweep 'bg-black/\[0\.03\]\s+dark:bg-black/20'             'bg-sunken'
sweep 'bg-black/(?:5|10|20)\s+dark:bg-black/(?:20|30)'    'bg-sunken'
sweep 'bg-gray-300\s+dark:bg-gray-600'                    'bg-line'
sweep 'bg-gray-200\s+dark:bg-gray-800'                    'bg-line'
sweep 'bg-white\s+dark:bg-gray-800(?![/\w-])'             'bg-field'

# ── Remaining borders ───────────────────────────────────────────────────────
sweep 'border-gray-300\s+dark:border-gray-700'    'border-field'
sweep 'border-gray-400\s+dark:border-gray-500'    'border-field'
sweep 'border-zinc-300\s+dark:border-zinc-600(?:/80)?' 'border-field'
sweep 'border-zinc-200\s+dark:border-zinc-700/60' 'border-line'
sweep 'border-white/(?:15|40)\s+dark:border-white/10' 'border-line'
sweep 'border-gray-200/60\s+dark:border-gray-800/60'  'border-line'

# ── Accent hovers & remaining blues ─────────────────────────────────────────
sweep 'hover:text-blue-600\s+dark:hover:text-blue-400' 'hover:text-accent'
sweep 'hover:bg-blue-50\s+dark:hover:bg-blue-500/20'   'hover:bg-accent-soft'
sweep 'bg-blue-50\s+dark:bg-blue-500/10'               'bg-accent-soft'
sweep 'text-blue-600\s+dark:text-blue-500'             'text-accent'
sweep 'text-blue-400\s+dark:text-blue-500'             'text-accent'
sweep 'empty:before:text-gray-400\s+dark:empty:before:text-gray-600' 'empty:before:text-ink-faint'

# ── Label colour dots ───────────────────────────────────────────────────────
sweep "'bg-gray-400 dark:bg-gray-500'" "'bg-ink-faint'"
sweep 'bg-gray-400 dark:bg-gray-500'   'bg-ink-faint'

echo "Done. Now: git diff"
