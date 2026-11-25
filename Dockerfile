# Tirelire image: FrankenPHP (Caddy + PHP 8.4) running as a non-root user, SQLite on a volume.
#
#   TIRELIRE_IMAGE=tirelire:local docker compose up -d --build
#   docker compose exec app php artisan finance:user you@example.com
#
# Published image (each release): ghcr.io/hiteafr/tirelire, see compose.yaml and the README.
#
# Base images are pinned by exact version AND by digest of the multi-architecture index (read on
# 2026-11-14 with `docker buildx imagetools inspect`). To change a version, change both together,
# never the tag alone; Dependabot proposes the updates.
#
# Stages: PHP dependencies (composer, without dev packages); assets (npm ci, vite build); final
# image. Lock files only, never composer update nor npm install. The build context is an allowlist
# (.dockerignore). The first two stages run on the platform of the build machine ($BUILDPLATFORM):
# their result (PHP code, built assets) does not depend on the architecture, so an arm64 image
# runs only its final stage under emulation.

# --- 1. PHP dependencies --------------------------------------------------------------------------
FROM --platform=$BUILDPLATFORM composer:2.11.2@sha256:5bcb28b8d0edc4091125f8218f9d088f998bdfc843f8fbb9a4beb4dcd8625684 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts --no-interaction

# --- 2. assets (Vite, Tailwind) -------------------------------------------------------------------
FROM --platform=$BUILDPLATFORM node:22.23.5-bookworm-slim@sha256:e9f565d32859f0cbfb52125eafe8be39390f24dac302dc3cdf566e8ba3ab21ee AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
# the whole code, vendor included: Tailwind also reads the framework's pagination views
COPY --from=vendor /app ./
RUN npm run build

# --- 3. final image -------------------------------------------------------------------------------
FROM dunglas/frankenphp:1.13.5-php8.4.27-bookworm@sha256:bd101be4e0f384dba7da85bfc8d13911998b4fee4ed42ec7959dc0e67619c161

ENV SERVER_NAME=:8080 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    TIRELIRE_DATA_DIR=/var/lib/tirelire

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && useradd --system --uid 10001 --user-group --no-create-home --shell /usr/sbin/nologin tirelire \
    && install -d -o tirelire -g tirelire -m 0750 /var/lib/tirelire \
    && chown -R tirelire:tirelire /data/caddy /config/caddy

# the code belongs to root (read-only for the application); only storage/ and bootstrap/cache/
# are writable by the tirelire user
WORKDIR /app
COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/tirelire-entrypoint
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-tirelire.ini"

RUN install -d storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs storage/app/private bootstrap/cache \
    && chown -R tirelire:tirelire storage bootstrap/cache \
    && su tirelire -s /bin/sh -c 'php artisan package:discover --no-interaction'

USER tirelire
VOLUME /var/lib/tirelire
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up > /dev/null || exit 1

ENTRYPOINT ["tirelire-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
