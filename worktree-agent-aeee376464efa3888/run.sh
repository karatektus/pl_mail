#!/bin/sh
# One-off container on the running test stack's network, worktree bind-mounted.
cd /home/karatektus/plmailstuff/pl_mail/.claude/worktrees/agent-aeee376464efa3888
exec docker run --rm --network pl_mail_test_default \
  -v "$PWD":/app -w /app \
  -e APP_ENV=test -e APP_DEBUG=1 \
  -e APP_SECRET=282065e0f71bf96c0dbcf0496c1f5d3e \
  -e APP_ENCRYPTION_KEY=emL6R8zs0pSXCVV2HvVvbFubriWJIla7kgnqSGqCJ8E= \
  -e "DATABASE_URL=postgresql://app:app@database:5432/app?serverVersion=18&charset=utf8" \
  -e MESSENGER_TRANSPORT_DSN=in-memory:// \
  -e MAILER_DSN=null://null \
  --entrypoint docker-php-entrypoint pl_mail_test-app "$@"
