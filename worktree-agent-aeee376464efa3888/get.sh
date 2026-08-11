#!/bin/sh
# Authenticated GET against the live plm_d stack. $1 = path, rest passed to curl.
J=/tmp/claude-1000/-home-karatektus-plmailstuff/b28e1914-8509-423e-8a57-1bb9498e8e5c/scratchpad/c2.txt
P="$1"; shift
exec curl -s -b "$J" -c "$J" -H 'Sec-Fetch-Site: same-origin' "$@" "http://127.0.0.1:8013$P"
