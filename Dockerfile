# syntax=docker/dockerfile:1.7

FROM php:8.5.8-cli-trixie@sha256:e2b5f2495f2a268082fbb88d8045511bd770390e062b21c35110be78e082d1a3 AS php-base

ARG INSTALL_XDEBUG=0

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PATH="/var/www/html/vendor/bin:${PATH}"

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        ca-certificates \
        curl \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        sockets \
        zip \
    && if [ "${INSTALL_XDEBUG}" = "1" ]; then pecl install xdebug && docker-php-ext-enable xdebug; fi \
    && rm -rf /tmp/pear ~/.pearrc /var/lib/apt/lists/*

COPY --from=composer:2.9@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d /usr/bin/composer /usr/local/bin/composer
COPY docker/php/conf.d/90-security.ini /usr/local/etc/php/conf.d/90-security.ini
COPY docker/php/conf.d/99-xdebug.ini /tmp/99-xdebug.ini

RUN if [ "${INSTALL_XDEBUG}" = "1" ]; then \
      mv /tmp/99-xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini; \
    else \
      rm -f /tmp/99-xdebug.ini; \
    fi

FROM php-base AS production-vendor

ENV APP_ENV=build

COPY composer.* artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes

RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs

RUN composer install \
        --no-dev \
        --prefer-dist \
        --optimize-autoloader \
        --no-interaction \
        --no-progress

# Keep this digest in sync with the vite service in docker-compose.yml
# (Dependabot only tracks this pin) so local dev and production assets build
# on the same Node.
FROM node:26-alpine@sha256:e88a35be04478413b7c71c455cd9865de9b9360e1f43456be5951032d7ac1a66 AS frontend-build

WORKDIR /app

COPY package*.json vite.config.js ./
COPY resources ./resources

RUN if [ -f package-lock.json ]; then \
      npm ci --no-audit --no-fund; \
    else \
      npm install --no-audit --no-fund; \
    fi
RUN npm run build

FROM php-base AS runtime-base

ARG APP_UID=1000
ARG APP_GID=1000

RUN if ! getent group "${APP_GID}" >/dev/null; then \
      groupadd --gid "${APP_GID}" app; \
    fi \
    && useradd --uid "${APP_UID}" --gid "${APP_GID}" --shell /bin/bash --create-home app \
    && mkdir -p /home/app/.composer/cache \
    && chown -R "${APP_UID}:${APP_GID}" /home/app/.composer

FROM runtime-base AS dev

ARG INSTALL_PCOV=0

COPY docker/php/conf.d/95-pcov.ini /tmp/95-pcov.ini

RUN if [ "${INSTALL_PCOV}" = "1" ]; then \
      pecl install pcov \
      && docker-php-ext-enable pcov \
      && cp /tmp/95-pcov.ini /usr/local/etc/php/conf.d/95-pcov.ini; \
    fi \
    && rm -f /tmp/95-pcov.ini \
    && rm -rf /tmp/pear ~/.pearrc

COPY --chown=app:app . /var/www/html

USER app

EXPOSE 8000

CMD ["sh", "/var/www/html/docker/start-local.sh"]

FROM dunglas/frankenphp:1-php8.5-alpine@sha256:070d9a37e02bf65c3cb14793218a8375f06839b0af6a5ccc6ab94379bbbf0517 AS production

# The base image is pinned by digest for a reproducible build graph, which also
# freezes its OS packages at whatever the digest shipped. Rather than a blanket
# `apk upgrade` (which reintroduces unpinned drift and is rejected by the release
# reproducibility gate), patch the specific packages Trivy flags -- targeted and
# version-pinned -- so the image carries no known-vulnerable package while the
# rest of the graph stays frozen to the digest. A CVE baked into the base by a
# non-apk artifact (e.g. the bundled FrankenPHP Go binary) can only be cleared by
# bumping the digest: this pin was bumped from d0833e14 to patch the Go stdlib
# CVE-2026-39822 (os.Root symlink traversal), unfixable via apk. Current apk pin:
# c-ares CVE-2026-33630, fixed in 1.34.8-r0.
RUN apk add --no-cache "c-ares>=1.34.8-r0"

RUN install-php-extensions \
    bcmath \
    intl \
    pcntl \
    pdo_pgsql \
    sockets \
    zip

ENV APP_ENV=production \
    APP_DEBUG=false

WORKDIR /var/www/html

COPY artisan composer.json ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY docker/Caddyfile docker/healthcheck-app.sh docker/start-production.sh docker/start-worker.sh docker/start-scheduler.sh ./docker/
COPY public ./public
COPY resources/views ./resources/views
COPY resources/js/artifact-preview-guard.js ./resources/js/artifact-preview-guard.js
COPY routes ./routes
COPY --from=production-vendor /var/www/html/vendor /var/www/html/vendor
COPY --from=frontend-build /app/public/build /var/www/html/public/build
COPY docker/php/conf.d/90-security.ini /usr/local/etc/php/conf.d/90-security.ini

RUN mkdir -p \
        /var/www/html/bootstrap/cache \
        /var/www/html/storage/app/private_artifacts \
        /var/www/html/storage/app/public \
        /var/www/html/storage/framework/cache \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/testing \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

VOLUME ["/var/www/html/storage/app"]

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=3 \
    CMD /bin/sh -c 'PORT="${PORT:-8080}" /var/www/html/docker/healthcheck-app.sh'

CMD ["sh", "/var/www/html/docker/start-production.sh"]
