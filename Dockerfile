# One image, three roles: CONTAINER_ROLE=app (web, runs migrations), worker (queue), scheduler.

# --- PHP dependencies -------------------------------------------------------------
FROM docker.io/composer:latest AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# --- Runtime ----------------------------------------------------------------------
FROM docker.io/dunglas/frankenphp:php8.4 AS app

LABEL org.opencontainers.image.title="modsync-web"
LABEL org.opencontainers.image.description="Publishes ModSync manifests"

RUN install-php-extensions \
        pdo_mysql \
        pdo_pgsql \
        intl \
        zip \
        bcmath \
        pcntl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'upload_max_filesize=256M\npost_max_size=256M\n' > "$PHP_INI_DIR/conf.d/zz-uploads.ini"

COPY --from=docker.io/composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && php artisan filament:assets \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENV CONTAINER_ROLE=app

HEALTHCHECK --interval=30s --timeout=10s --start-period=2m --retries=3 \
    CMD test "$CONTAINER_ROLE" != "app" || curl -fsS http://localhost/up > /dev/null || exit 1

ENTRYPOINT ["entrypoint"]
