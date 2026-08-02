#!/bin/sh
set -e

# Export every KEY=VALUE from the generated secrets file that is not already in
# the environment. Explicit configuration always wins: an operator who manages
# secrets elsewhere never has a generated value substituted underneath them.
load_generated_secrets() {
	[ -f "$1" ] || return 0

	while IFS='=' read -r key value; do
		case "$key" in '' | \#*) continue ;; esac
		[ -n "$(printenv "$key" || true)" ] && continue
		export "$key=$value"
	done <"$1"
}

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	# ── Generated secrets ──────────────────────────────────────────────────
	# A fresh install must not come up on the secrets committed to the repo.
	# APP_ENCRYPTION_KEY in particular is all that stands between a stolen
	# database dump and every mailbox password inside it, so an install that
	# inherits the repository's value is readable by anyone holding the repo.
	#
	# The `secrets-init` service has normally done this already, before Postgres
	# and Mercure started — they read their secrets at container-create time and
	# cannot wait for us. Running it again here is free and covers a stack
	# assembled without that service.
	#
	# All app services mount the same directory. If one is missing that volume it
	# mints its own key and quietly fails to read what the others wrote —
	# `app:secrets:init` below probes for exactly that and refuses to start
	# rather than write data nobody can decrypt.
	SECRETS_DIR="${APP_SECRETS_DIR:-/app/var/secrets}"
	SECRETS_FILE="$SECRETS_DIR/generated.env"

	generate-secrets

	export APP_SECRETS_FILE="$SECRETS_FILE"
	load_generated_secrets "$SECRETS_FILE"

	# True only for a DSN that carries a password, i.e. one an operator actually
	# configured.
	#
	# "Is it empty" is not the test, because it is never empty: compose resolves
	# ${DATABASE_URL:-} against the project .env, and the .env default is a
	# credential-less DSN on purpose — Doctrine reads the driver out of the
	# scheme, and a blank DSN has none, which breaks the prod cache warmup during
	# the image build. Treating that placeholder as operator intent is what stops
	# the generated password from ever being spliced in, and Postgres then
	# refuses the connection with "no password supplied".
	database_url_has_password() {
		case "$1" in
			*://*) ;;
			*) return 1 ;;
		esac

		_userinfo="${1#*://}"

		# No "@" at all, or one that only shows up later in the path or query:
		# no userinfo, so no password.
		case "$_userinfo" in
			*@*) _userinfo="${_userinfo%%@*}" ;;
			*) return 1 ;;
		esac

		case "$_userinfo" in
			*/*) return 1 ;;
			*:*) return 0 ;;
			*) return 1 ;;
		esac
	}

	# The database password is generated too, so the connection string has to be
	# assembled after the fact. Only when nothing supplied one: an operator with
	# an external database sets DATABASE_URL and never reaches this.
	if ! database_url_has_password "$DATABASE_URL" && [ -n "$POSTGRES_PASSWORD" ]; then
		export DATABASE_URL="postgresql://${POSTGRES_USER:-app}:${POSTGRES_PASSWORD}@${POSTGRES_HOST:-database}:5432/${POSTGRES_DB:-app}?serverVersion=${POSTGRES_VERSION:-18}&charset=${POSTGRES_CHARSET:-utf8}"
	fi

	# Install the project the first time PHP is started
	# After the installation, the following block can be deleted
	if [ ! -f composer.json ]; then
		rm -Rf tmp/
		composer create-project "symfony/skeleton $SYMFONY_VERSION" tmp --stability="$STABILITY" --prefer-dist --no-progress --no-interaction --no-install

		cd tmp
		composer require "php:>=$PHP_VERSION" runtime/frankenphp-symfony
		composer config --json extra.symfony.docker 'true'

		cp -Rp . ..
		cd -
		rm -Rf tmp/
	fi

	# Dependencies are baked into the prod image, so none of this runs for the
	# published one — vendor/ is already there. It runs for the dev stack, which
	# bind-mounts the source tree over /app: a fresh clone has no vendor/, and
	# every bin/console below would die on "Dependencies are missing. Try running
	# composer install." Doing it here is what keeps `docker compose up` the only
	# command a contributor has to run.
	#
	# php, the three workers and tailwind all start within milliseconds of each
	# other against that one shared bind mount, so this has to be mutually
	# exclusive — five concurrent installs over the same vendor/ can leave a
	# corrupt autoloader behind.
	#
	# mkdir is the mutex, not flock. flock is advisory and does not reliably
	# exclude across containers on a macOS bind mount: the lock file is created
	# by the very redirect that acquires it, and two simultaneous O_CREATs over
	# VirtioFS do not necessarily end up sharing lock state. Measured, not
	# assumed — php and imap-supervisor both entered a flock-guarded block 17ms
	# apart. mkdir is a single atomic operation that fails with EEXIST, which is
	# the property actually needed here.
	install_dependencies_once() {
		_lock='.composer-install.lock'
		_waited=0

		while [ ! -f vendor/autoload_runtime.php ]; do
			if mkdir "$_lock" 2>/dev/null; then
				echo 'Installing PHP dependencies — first run only, this takes a few minutes...'
				composer install --prefer-dist --no-progress --no-interaction
				_status=$?
				rmdir "$_lock" 2>/dev/null || true

				return $_status
			fi

			[ "$_waited" -eq 0 ] && echo 'Another container is installing PHP dependencies; waiting...'

			sleep 5
			_waited=$((_waited + 5))

			# Nothing should take this long. If it has, the container that held
			# the lock died mid-install and left it behind — clear it and take
			# over rather than waiting out the clock forever.
			if [ "$_waited" -ge 900 ]; then
				echo 'Timed out waiting for the install to finish; clearing a stale lock.' >&2
				rmdir "$_lock" 2>/dev/null || true
				_waited=0
			fi
		done
	}

	if [ -f composer.json ] && [ ! -f vendor/autoload_runtime.php ]; then
		install_dependencies_once
	fi

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		# app:db:migrate, not doctrine:migrations:migrate — it runs exactly
		# that, with exactly these flags, but holds a Postgres advisory lock
		# across the whole run.
		#
		# php, imap-supervisor, messenger-worker and scheduler all reach this
		# line within milliseconds of each other against one database, and all
		# four read the migration ledger before any of them writes to it. One
		# applies the change and commits; the others block on its table lock and
		# then fail on a schema that has already moved — "column ... already
		# exists". With `set -e` above, that is three services that never start.
		#
		# The lock cannot live in this script: pg_advisory_lock is scoped to the
		# database session, so a `dbal:run-sql` that takes it hands it straight
		# back on exit and leaves the migrate that follows unprotected. It has
		# to be the same connection that migrates, which means one PHP process
		# doing both. See src/Command/Setup/MigrateCommand.php.
		if [ "$( find ./migrations -iname '*.php' -print -quit )" ]; then
			php bin/console app:db:migrate --no-interaction
		fi

		# The secrets that need PHP: a VAPID keypair and the JWT keys. Runs
		# after migrations because it also verifies that the encryption key in
		# force can actually open the credentials already in the database —
		# the one check that catches a container holding the wrong key before
		# it writes anything.
		#
		# Fatal only when starting the server. A console invocation is how an
		# operator FIXES a key mismatch, so refusing to run one turns a
		# recoverable problem into a locked door: the container would not start,
		# and neither would the command that clears the unreadable rows.
		if [ "$1" = 'frankenphp' ]; then
			php bin/console app:secrets:init
		elif ! php bin/console app:secrets:init; then
			echo 'Continuing anyway: this is a console invocation, and refusing it would'
			echo 'block the very commands that repair a key mismatch.'
		fi
		load_generated_secrets "$SECRETS_FILE"
	fi

	# POSIX ACLs are a convenience, not a requirement: this image runs as root,
	# so it can already write everything under var/.
	#
	# They MUST NOT be able to stop the container from starting. var/attachments,
	# var/raw and var/uploads are bind-mounted from host storage, and on ZFS
	# (TrueNAS) — or NFS, or a Docker Desktop virtiofs share — setfacl fails with
	# "Operation not supported" because those filesystems use NFSv4 ACLs rather
	# than POSIX ones. With `set -e` at the top of this script, that aborted the
	# entrypoint before it ever reached exec.
	if ! setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var 2>/dev/null; then
		echo 'Note: POSIX ACLs are not supported on this filesystem; skipping setfacl for var/.'
	fi

	setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var 2>/dev/null || true
fi

exec docker-php-entrypoint "$@"
