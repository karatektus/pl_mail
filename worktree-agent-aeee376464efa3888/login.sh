#!/bin/sh
J=/tmp/claude-1000/-home-karatektus-plmailstuff/b28e1914-8509-423e-8a57-1bb9498e8e5c/scratchpad/c2.txt
rm -f "$J"
curl -s -c "$J" -o /dev/null http://127.0.0.1:8013/login
curl -s -b "$J" -c "$J" -X POST \
  -H 'Sec-Fetch-Site: same-origin' \
  -d 'email=e2e@plmail.test' -d 'password=testpass123' -d '_csrf_token=csrf-token' \
  -o /dev/null -w 'login: %{http_code} -> %{redirect_url}\n' \
  http://127.0.0.1:8013/login
