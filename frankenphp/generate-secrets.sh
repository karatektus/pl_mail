#!/bin/sh
# Mint the secrets a plMail install needs, once, into a directory every service
# shares.
#
# Runs twice over in a normal stack, and that is the point:
#
#   - as the `secrets-init` service, before anything else starts, because
#     Postgres and Mercure read their secrets at container-create time and
#     cannot wait for the app;
#   - from the app entrypoint, so a stack assembled without that service — or a
#     `docker compose run` — still comes up with real secrets.
#
# Idempotent, and safe to run concurrently: everything happens under one lock,
# and a value already in the file or already in the environment is left alone.
# That last part matters — an operator who sets APP_ENCRYPTION_KEY themselves
# must never have a generated one substituted underneath them.
#
# /dev/urandom rather than openssl: the image installs only acl, file, gettext
# and git on top of the base, and this must not depend on a package nobody
# declared.
set -e

SECRETS_DIR="${APP_SECRETS_DIR:-/app/var/secrets}"
SECRETS_FILE="$SECRETS_DIR/generated.env"
# The Postgres image reads its password from a file of its own, so that one is
# written twice: as a line here, and as this bare file.
POSTGRES_PASSWORD_FILE_PATH="$SECRETS_DIR/postgres_password"

mkdir -p "$SECRETS_DIR"
chmod 700 "$SECRETS_DIR" 2>/dev/null || true

# Hex, not base64: POSTGRES_PASSWORD is spliced into a DATABASE_URL and
# MERCURE_JWT_SECRET into a Caddy config, and neither wants +, / or =.
random_hex() {
	head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'
}

random_base64() {
	head -c "$1" /dev/urandom | base64 | tr -d '\n'
}

# generate NAME VALUE — append NAME to the secrets file unless the environment
# already carries it or the file already has it.
generate() {
	name="$1"
	value="$2"

	if [ -n "$(printenv "$name" || true)" ]; then
		return 0
	fi

	if grep -q "^$name=" "$SECRETS_FILE"; then
		return 0
	fi

	echo "$name=$value" >>"$SECRETS_FILE"
	echo "Generated $name."
}

(
	flock 9

	[ -f "$SECRETS_FILE" ] || : >"$SECRETS_FILE"
	chmod 600 "$SECRETS_FILE" 2>/dev/null || true

	generate APP_SECRET "$(random_hex 32)"

	# 32 bytes, base64 — the size libsodium secretbox requires; see
	# App\Infrastructure\Encryption\Encryptor. Losing this one loses every
	# stored mail credential, which is why the directory is worth backing up.
	generate APP_ENCRYPTION_KEY "$(random_base64 32)"

	generate POSTGRES_PASSWORD "$(random_hex 24)"
	generate MERCURE_JWT_SECRET "$(random_hex 32)"

	# 0644 rather than 0600: the Postgres image reads this as uid 999, and the
	# file only ever exists inside a volume mounted into plMail's own services.
	password="$(printenv POSTGRES_PASSWORD || true)"
	[ -n "$password" ] || password="$(sed -n 's/^POSTGRES_PASSWORD=//p' "$SECRETS_FILE")"

	if [ -n "$password" ]; then
		printf '%s' "$password" >"$POSTGRES_PASSWORD_FILE_PATH"
		chmod 644 "$POSTGRES_PASSWORD_FILE_PATH" 2>/dev/null || true
	fi
) 9>"$SECRETS_DIR/.lock"
