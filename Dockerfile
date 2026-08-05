#syntax=docker/dockerfile:1

# Versions
FROM dunglas/frankenphp:1-php8.4 AS frankenphp_upstream

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

# Which build this is, for the admin panel to show.
#
# It has to arrive here as an argument, because the image has no history to ask:
# the source is copied in and .git stays behind. Left empty by a plain
# `docker build`, which is correct — an image nobody stamped honestly does not
# know what it is, and AppVersion says "development" rather than guessing.
# The release workflow passes both from the metadata action.
ARG APP_VERSION=""
ARG APP_COMMIT=""
ENV APP_VERSION=$APP_VERSION
ENV APP_COMMIT=$APP_COMMIT

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/
COPY --link frankenphp/worker.Caddyfile /etc/frankenphp/worker.Caddyfile

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

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
    chmod +x bin/console; sync;
