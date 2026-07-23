#!/usr/bin/env bash
set -euo pipefail

FILES=$(find templates -name '*.html.twig')

sweep() {
    perl -0777 -pi -e "s{(?<![\\w:./-])$1}{$2}g" $FILES
}
sweep 'bg-blue-600(?!\s+hover)' 'bg-accent'
sweep 'text-blue-600\s+border-blue-600' 'text-accent border-accent'
sweep 'focus:ring-blue-500' 'focus:ring-accent'
sweep 'focus-visible:ring-blue-500' 'focus-visible:ring-accent'
sweep 'hover:border-blue-500' 'hover:border-accent'
sweep 'focus:border-blue-500' 'focus:border-accent'
sweep 'text-blue-500' 'text-accent'
sweep 'bg-blue-500(?![/\w-])' 'bg-accent'
echo "Done. Now: git diff"
