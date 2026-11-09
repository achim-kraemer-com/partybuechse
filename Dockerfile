# syntax=docker/dockerfile:1.7
#
# Partybüchse – single container: Apache + PHP 8.3 + SQLite (in a volume).
#
#   docker compose up --build            # production-like, http://127.0.0.1:8000
#   DOCKER_APP_ENV=dev docker compose up --build   # with profiler and verbose errors
#
FROM php:8.3-apache AS app

ARG APP_ENV=prod
ENV APP_ENV=${APP_ENV} \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_FUND=0

# unzip for Composer dist installs, opcache for speed. sqlite3 + pdo_sqlite ship with the official image.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*; \
    docker-php-ext-install -j"$(nproc)" opcache; \
    a2enmod headers rewrite

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-partybuechse.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Dependencies first so they are cached between source changes.
COPY composer.json composer.lock* symfony.lock* ./
RUN set -eux; \
    if [ "$APP_ENV" = "prod" ]; then \
        composer install --no-dev --no-scripts --prefer-dist --no-progress --no-autoloader; \
    else \
        composer install --no-scripts --prefer-dist --no-progress --no-autoloader; \
    fi

COPY . .

RUN set -eux; \
    if [ "$APP_ENV" = "prod" ]; then \
        composer dump-autoload --no-dev --classmap-authoritative; \
    else \
        composer dump-autoload --optimize; \
    fi; \
    mkdir -p var/cache var/log var/data; \
    chown -R www-data:www-data var; \
    chmod +x bin/console docker/entrypoint.sh

VOLUME ["/var/www/html/var/data"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/ >/dev/null || exit 1

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
