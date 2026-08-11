#!/bin/sh
D=/home/karatektus/plmailstuff/pl_mail/.claude/worktrees/agent-aeee376464efa3888/worktree-agent-aeee376464efa3888
for p in "$@"; do
  printf '%s -> ' "$p"
  "$D/get.sh" "$p" -o /dev/null -w '%{http_code}\n'
done
