# syntax=docker/dockerfile:1
# Tidemark Billing as a single container: FrankenPHP (Caddy + PHP) serving the app, with the scheduler and a queue worker beside it.
# Everything that changes lives in the /data volume: .env, the SQLite database, uploads, logs and extra plugins.

FROM composer:2 AS build
WORKDIR /app
COPY . /app
# ext-bcmath is installed in the runtime image below; this stage only fetches PHP dependencies.
RUN composer install --ignore-platform-req=ext-bcmath --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-scripts --prefer-dist

FROM dunglas/frankenphp:1-php8.4-alpine

RUN install-php-extensions pdo_mysql pdo_pgsql pdo_sqlite mbstring bcmath intl zip opcache sodium \
    && apk add --no-cache tzdata su-exec \
    && addgroup -g 1000 billing && adduser -D -u 1000 -G billing billing

ARG VERSION=dev
WORKDIR /app
COPY --from=build --chown=billing:billing /app /app
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/billing-entrypoint
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-billing.ini

RUN echo "${VERSION}" > /app/VERSION \
    && rm -rf /app/tests /app/dist /app/.github /app/.git /app/storage \
    && ln -s /data/storage /app/storage \
    && ln -s /data/.env /app/.env \
    && ln -s /data/database.sqlite /app/database/database.sqlite \
    && mkdir -p /data /config /app/bootstrap/cache \
    && chown billing:billing /app \
    && chown -R billing:billing /data /config /app/bootstrap/cache \
    && chmod +x /usr/local/bin/billing-entrypoint

# The image runs with a read-only root filesystem: /data, /config and /tmp are the only places written to.
# Plugins bundled in the image live in /app/plugins. Put your own in /data/plugins (they are discovered too).
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    QUEUE_CONNECTION=database \
    BILLING_EXTERNAL_WORKER=1 \
    BILLING_EXTRA_PLUGINS_PATH=/data/plugins \
    APP_SERVICES_CACHE=/tmp/services.php \
    APP_PACKAGES_CACHE=/tmp/packages.php \
    APP_CONFIG_CACHE=/tmp/config.php \
    APP_ROUTES_CACHE=/tmp/routes.php \
    APP_EVENTS_CACHE=/tmp/events.php \
    HOME=/config \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/config \
    SERVER_NAME=:8080

# Starts as root only to match the owner of /data to PUID/PGID (default 1000), then drops to that user.
VOLUME /data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD wget -q -O /dev/null http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["billing-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
