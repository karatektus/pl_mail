#syntax=docker/dockerfile:1

# Versions
FROM dunglas/frankenphp:1-php8.4 AS frankenphp_upstream

# The Mercure hub, taken as a binary rather than run as an image. plMail runs it
# as one of the background processes in the worker container (app:work), which
# answers to the name `mercure` on the stack's network: the web container
# proxies /.well-known/mercure there and the application publishes there, as
# they always did to a container of that name.
#
# Not the hub FrankenPHP has built in. That one is a release behind (0.24 in
# FrankenPHP 1.12), and plMail speaks the 1.0 protocol, whose RFC 9068 tokens a
# 0.x hub refuses. That exact mismatch, the other way round, stopped live
# updates in v0.2.31.
#
# `v1`, not an exact version: the hub keeps itself current within the protocol
# plMail speaks, the way the floating `dunglas/mercure` tag was meant to, but it
# now moves with plMail's own releases rather than whenever a host last pulled.
# A static Go binary, so an Alpine build runs on this Debian image as it is.
FROM docker.io/dunglas/mercure:v1 AS mercure_upstream

# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/develop/develop-images/multistage-build/#stop-at-a-specific-build-stage
# https://docs.docker.com/compose/compose-file/#target


# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

# NOTE: there is deliberately no `VOLUME /app/var/` here.
#
# It gave every container its own anonymous volume at /app/var, so attachments
# and raw messages written by the sync workers were invisible to the web
# container that serves them — downloads 404'd, and the data vanished on every
# container recreate. The durable paths are now bound explicitly per service
# (var/attachments, var/raw, var/uploads); see compose.override.yaml and
# truenas.compose.yaml. Re-adding this line silently breaks blob download again.

# persistent / runtime deps
# hadolint ignore=DL3008
RUN apt-get update && apt-get install -y --no-install-recommends \
	acl \
	file \
	gettext \
	git \
	&& rm -rf /var/lib/apt/lists/*

# pg_dump, for `app:backup`.
#
# From PGDG rather than Debian, and pinned to the same major as the server:
# pg_dump refuses outright to dump a server newer than itself, and Debian's
# postgresql-client trails 18 — so the distro package would install cleanly and
# then fail at the moment someone actually needed a backup.
#
# PGDG publishes amd64 and arm64, which the two-runner image build requires.
# hadolint ignore=DL3008
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends curl ca-certificates gnupg; \
	install -d /usr/share/postgresql-common/pgdg; \
	curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
		-o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc; \
	echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(. /etc/os-release && echo "$VERSION_CODENAME")-pgdg main" \
		> /etc/apt/sources.list.d/pgdg.list; \
	apt-get update; \
	apt-get install -y --no-install-recommends postgresql-client-18; \
	rm -rf /var/lib/apt/lists/*

RUN set -eux; \
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		zip \
		pdo_pgsql \
        pgsql \
        pcntl \
        gmp \
        # Attachment previews only — see AttachmentThumbnailer, which degrades
        # to the paperclip icon where this is missing.
        gd \
	;

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1


COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
# Its own binary, not just a step inside the entrypoint: the secrets-init
# service runs it on its own, before Postgres and Mercure start.
COPY --link --chmod=755 frankenphp/generate-secrets.sh /usr/local/bin/generate-secrets
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile
# The hub and its own Caddyfile, verbatim: the image's default config (the one
# that requires a subscriber JWT), not the dev one. See mercure_upstream above.
COPY --link --from=mercure_upstream /usr/bin/caddy /usr/local/bin/mercure
COPY --link --from=mercure_upstream /etc/caddy/Caddyfile /etc/mercure/Caddyfile

# Runnable as a user that is not root, and not any particular one.
#
# Nothing here needs root at run time, and a platform that picks the uid itself
# (the TrueNAS catalogue runs apps as 568 by default, with every capability
# dropped) found two things in the way before PHP ever started:
#
#   - The FrankenPHP binary carries cap_net_bind_service as a FILE capability.
#     A process that does not hold that capability may not execute such a file
#     at all: "exec: frankenphp: Operation not permitted". It is there so a
#     non-root user can bind port 80 on a bare host; in a container the
#     unprivileged port range starts at 0, so it buys nothing and costs the
#     start. Root keeps binding 80 exactly as before.
#   - /data and /config, where the web server and the hub keep their state,
#     belong to root. A new volume mounted there copies the directory's
#     permissions, so the hub died on "open /data/caddy/mercure.db: permission
#     denied". Writable by anyone, because the uid is not known here; there is
#     one tenant in this container.
RUN set -eux; \
	setcap -r /usr/local/bin/frankenphp; \
	mkdir -p /data/caddy /config/caddy; \
	chmod -R a+rwX /data /config

ENTRYPOINT ["docker-entrypoint"]

# /healthz, not Caddy's metrics port. The old probe answered as soon as the web
# server was listening, which is true well before PHP can reach the database —
# so a stack with an unreachable database reported itself healthy, and
# `depends_on: service_healthy` waited for nothing worth waiting for.
#
# The app endpoint returns 503 when the database is down and 200 otherwise; see
# App\Controller\HealthController for why a backed-up queue deliberately stays
# 200. The worker services have no HTTP server and disable this in compose —
# their liveness reaches the same endpoint through heartbeats instead.
HEALTHCHECK --start-period=60s CMD curl -f http://localhost/healthz || exit 1
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN set -eux; \
	install-php-extensions \
		xdebug \
	;

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod
ENV FRANKENPHP_CONFIG="import worker.Caddyfile"

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/
COPY --link frankenphp/worker.Caddyfile /etc/frankenphp/worker.Caddyfile

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# Which build this is, for the admin panel to show.
#
# It has to arrive here as an argument, because the image has no history to ask:
# the source is copied in and .git stays behind. Left empty by a plain
# `docker build`, which is correct — an image nobody stamped honestly does not
# know what it is, and AppVersion says "development" rather than guessing.
# The release workflow passes both from the metadata action.
#
# BELOW THE VENDOR INSTALL, AND THAT IS THE WHOLE POINT OF WHERE IT SITS.
# These two lines were above it, which quietly defeated the line the comment
# four lines up promises: every layer after an ENV is keyed on that ENV's value,
# and APP_COMMIT is a different value on every single commit. So the vendor
# layer was never once reused — every build of every branch re-ran
# `composer install` and re-downloaded 111 packages, and the cache the workflow
# carefully scopes per architecture could only ever hit the layers ABOVE this
# point.
#
# It is a build-time cost and it was also a reliability one, which is how it was
# found: a step that runs on every build is a step that needs the network on
# every build, and `composer install` failing on a release is a release that
# does not ship until somebody presses a button. Moved down, the vendors are
# keyed on composer.lock alone — they change when the dependencies change, which
# is what the original comment always meant.
#
# It cannot move any further down: `composer dump-env prod` below snapshots the
# environment into .env.local.php, and these have to be in it by then.
ARG APP_VERSION=""
ARG APP_COMMIT=""
ENV APP_VERSION=$APP_VERSION
ENV APP_COMMIT=$APP_COMMIT
# Where this image is published, e.g. ghcr.io/karatektus/pl_mail: what Admin →
# Updates asks for newer builds. Stamped rather than hard-coded so a fork that
# publishes its own image checks its own. The same value on every build, so it
# costs the layer cache nothing.
ARG APP_IMAGE=""
ENV APP_IMAGE=$APP_IMAGE

# copy sources
COPY --link . ./
RUN rm -Rf frankenphp/

RUN set -eux; \
    mkdir -p var/cache var/log; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer dump-env prod; \
    composer run-script --no-dev post-install-cmd; \
    php bin/console tailwind:build --minify; \
    php bin/console asset-map:compile; \
    chmod +x bin/console; \
    # The directories PHP writes at run time, for whichever user that turns out
    # to be — see the note on running without root in the base stage. var/ itself
    # as well, not recursively: the default secrets directory is created in it
    # on first start.
    mkdir -p var/share; \
    chmod a+rwx var; \
    chmod -R a+rwX var/cache var/log var/share; \
    sync;
