#!/bin/sh
# Prepares the runtime (cache, database) and hands over to Apache.
set -eu

cd /var/www/html

: "${APP_ENV:=prod}"
export APP_ENV

# opcache: re-check changed files only outside prod (picked up by docker/php.ini)
if [ -z "${PHP_OPCACHE_VALIDATE_TIMESTAMPS:-}" ]; then
    if [ "$APP_ENV" = "prod" ]; then PHP_OPCACHE_VALIDATE_TIMESTAMPS=0; else PHP_OPCACHE_VALIDATE_TIMESTAMPS=1; fi
fi
export PHP_OPCACHE_VALIDATE_TIMESTAMPS

mkdir -p var/cache var/log var/data

if [ -z "${HOST_USERNAME:-}" ] || { [ -z "${HOST_PASSWORD:-}" ] && [ -z "${HOST_PASSWORD_HASH:-}" ]; }; then
    echo "[partybuechse] WARNING: HOST_USERNAME / HOST_PASSWORD not set – the host area cannot be opened." >&2
fi
if [ -z "${SPOTIFY_CLIENT_ID:-}" ] || [ -z "${SPOTIFY_CLIENT_SECRET:-}" ]; then
    echo "[partybuechse] WARNING: SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET not set – Spotify cannot be connected." >&2
fi
if [ "$APP_ENV" = "prod" ] && [ "${APP_SECRET:-}" = "change-me-in-env-local-5f2b9c1e7a4d" ]; then
    echo "[partybuechse] WARNING: APP_SECRET still has the default value. Set a random one for production." >&2
fi

echo "[partybuechse] env=$APP_ENV – warming cache"
php bin/console cache:clear --no-warmup --no-ansi
php bin/console cache:warmup --no-ansi

echo "[partybuechse] running migrations"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --no-ansi

chown -R www-data:www-data var

echo "[partybuechse] ready – starting: $*"
exec "$@"
