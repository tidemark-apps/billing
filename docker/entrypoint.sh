#!/bin/sh
# Points the app's writable paths at the /data volume, creates the settings file on first run,
# migrates on start, and runs the scheduler and a queue worker beside the web server.
set -e

# As root: give /data (and the few writable spots in /app) to PUID:PGID, then run this script again as that user.
# Unraid uses 99:100 (nobody:users). Starting the container as a non-root user (docker --user) skips this.
if [ "$(id -u)" = "0" ]; then
    PUID="${PUID:-1000}"
    PGID="${PGID:-1000}"
    mkdir -p /data /config
    [ "$(stat -c %u:%g /data)" = "$PUID:$PGID" ] || chown -R "$PUID:$PGID" /data
    chown -R "$PUID:$PGID" /config /app/bootstrap/cache
    chown "$PUID:$PGID" /app /app/database
    exec su-exec "$PUID:$PGID" "$0" "$@"
fi

DATA=/data
mkdir -p "$DATA/storage/app/public" "$DATA/storage/framework/cache/data" "$DATA/storage/framework/sessions" "$DATA/storage/framework/views" "$DATA/storage/logs" "$DATA/plugins"
# /app/storage, /app/.env and /app/database/database.sqlite are links into /data made when the image is built
# (older images made them here), so the app directory itself can be read-only.
[ -L /app/storage ] || ln -sfn "$DATA/storage" /app/storage

if [ ! -f "$DATA/.env" ]; then
    cp /app/.env.example "$DATA/.env"
    KEY="base64:$(head -c 32 /dev/urandom | base64)"
    sed -i "s#^APP_KEY=.*#APP_KEY=${KEY}#" "$DATA/.env"
fi
[ -L /app/.env ] || ln -sf "$DATA/.env" /app/.env

# SQLite lives in the volume; the installer and the default .env both point at database/database.sqlite
[ -f "$DATA/database.sqlite" ] || : > "$DATA/database.sqlite"
[ -L /app/database/database.sqlite ] || ln -sf "$DATA/database.sqlite" /app/database/database.sqlite

rm -f /app/bootstrap/cache/*.php 2>/dev/null || true

if [ -f "$DATA/storage/app/installed" ]; then
    # already installed: apply any new migrations from the updated image
    php /app/artisan migrate --force --no-interaction || true
elif [ -n "$BILLING_ADMIN_EMAIL" ] && { [ -n "$BILLING_ADMIN_PASSWORD" ] || [ "$BILLING_HOSTED" = "true" ] || [ "$BILLING_HOSTED" = "1" ]; }; then
    # unattended first run: create the admin from environment variables (a hosted install needs no password: it prints a login link instead)
    php /app/artisan billing:install --no-interaction || true
fi

# the scheduler (renewal invoices, reminders, suspensions) and a queue worker (provisioning, webhook deliveries)
( while true; do php /app/artisan schedule:run >/dev/null 2>&1 || true; sleep 60; done ) &
( while true; do php /app/artisan queue:work --sleep=3 --tries=3 --max-time=3600 >/dev/null 2>&1 || true; sleep 2; done ) &

exec "$@"
