#!/bin/sh
# Count result rows the search page returns for each query given.
D=/home/karatektus/plmailstuff/pl_mail/.claude/worktrees/agent-aeee376464efa3888/worktree-agent-aeee376464efa3888
for q in "$@"; do
  n=$("$D/get.sh" "/mail/search?q=$q" | grep -oE '/mail/thread/[0-9]+' | sort -u | wc -l)
  printf '%-24s %s\n' "$q" "$n"
done
