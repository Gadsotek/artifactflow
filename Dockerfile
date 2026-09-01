# syntax=docker/dockerfile:1.7

FROM php:8.5.9-cli-trixie@sha256:54d82ff9be6bd198145e90c917fc9b2e24230b42e52def8deb3554baf61c451a AS php-base

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
FROM node:26-alpine@sha256:aadf416b2cdce311a8811ba3f0608a61b77dbf997500e2eafe781b51f6a0b019 AS frontend-build

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

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" exif gd \
    && if [ "${INSTALL_PCOV}" = "1" ]; then \
      pecl install pcov \
      && docker-php-ext-enable pcov \
      && cp /tmp/95-pcov.ini /usr/local/etc/php/conf.d/95-pcov.ini; \
    fi \
    && rm -f /tmp/95-pcov.ini \
    && rm -rf /tmp/pear ~/.pearrc /var/lib/apt/lists/*

COPY --chown=app:app . /var/www/html

USER app

EXPOSE 8000

CMD ["sh", "/var/www/html/docker/start-local.sh"]

# Native raster decoding lives in a separate minimal image. It receives no
# application source, database client, credentials, or artifact storage.
FROM php:8.5.9-cli-alpine3.24@sha256:0554eb53778b5316f6b9a3447c9dfa3cf2141c0c02ff816c42cdc9aa240a34aa AS image-parser

# The pinned base digest freezes its Alpine packages. Patch only the OpenSSL
# packages Trivy reports for CVE-2026-14456 rather than running a blanket
# `apk upgrade`; the published image digest and SBOM identify the exact result.
RUN apk add --no-cache --virtual .image-parser-build-deps \
        $PHPIZE_DEPS \
        libjpeg-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" exif gd \
    && apk add --no-cache \
        ca-certificates \
        "c-ares>=1.34.8-r0" \
        libjpeg-turbo \
        libpng \
        "libcrypto3>=3.5.8-r0" \
        "libssl3>=3.5.8-r0" \
        "openssl>=3.5.8-r0" \
        socat=1.8.1.3-r0 \
    && apk del .image-parser-build-deps

COPY image-parser /srv/image-parser

RUN addgroup -S -g 10001 image-parser \
    && adduser -S -D -H -u 10001 -G image-parser -s /sbin/nologin image-parser \
    && mkdir -p /run/artifactflow/image-parser \
    && chown image-parser:image-parser /run/artifactflow/image-parser \
    && chmod 0755 /run/artifactflow/image-parser \
    && chmod 0555 /srv/image-parser/start.sh

WORKDIR /srv/image-parser

USER image-parser

# Keep one normalization process inside the 512 MiB service cgroup. A prefork
# pool multiplies native GD decode/rotate/encode memory, and a killed child can
# leave the parent listener deceptively healthy. The healthcheck performs its
# tiny decode/re-encode in a separate CLI process, so a long normalization cannot
# starve it. Scale with separate parser containers and memory limits instead.
HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 \
    CMD ["php", "/srv/image-parser/healthcheck.php"]

CMD ["/srv/image-parser/start.sh"]

# FrankenPHP v1.12.6 still resolves vulnerable Go dependencies. Rebuild the
# bundled binary with patched versions until upstream ships them or newer.
FROM dunglas/frankenphp:builder-php8.5-alpine@sha256:764a5d89d0042aba4b652225e283f439f63d8505ded2c53158184bb758015640 AS frankenphp-security-builder

ENV PATH="/usr/local/go/bin:${PATH}"

WORKDIR /go/src/app/caddy

RUN go version | grep -E '^go version go1\.26\.6[[:space:]]' \
    && go get golang.org/x/crypto@v0.55.0 \
    && go get google.golang.org/grpc@v1.82.1 \
    && go get github.com/getkin/kin-openapi@v0.144.0 \
    && go mod tidy

WORKDIR /go/src/app/caddy/frankenphp

RUN GOBIN=/usr/local/bin ../../go.sh install \
        -ldflags "-w -s -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP v1.12.6 PHP $PHP_VERSION Caddy' -X 'github.com/caddyserver/caddy/v2.CustomBinaryName=frankenphp' -X 'github.com/caddyserver/caddy/v2/modules/caddyhttp.ServerHeader=FrankenPHP Caddy'" \
        -buildvcs=true \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'google\.golang\.org/grpc[[:space:]]+v1\.82\.1' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'github\.com/getkin/kin-openapi[[:space:]]+v0\.144\.0' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'golang\.org/x/crypto[[:space:]]+v0\.55\.0'

FROM dunglas/frankenphp:1-php8.5-alpine@sha256:def035e964f46253cb5e46a1f9a4633370f658b8e410305e0730ce7247d0ab6a AS production

COPY --from=frankenphp-security-builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

# The base image is pinned by digest, which freezes the OS packages it shipped.
# Rather than a blanket `apk upgrade`, patch only packages Trivy flags. The
# minimum-version constraint below intentionally permits a later patched Alpine
# revision, so this layer is not byte-for-byte reproducible from the base digest
# alone; the release image digest, SBOM, and provenance identify the exact output.
# The source-rebuilt binary above uses Go 1.26.6 for CVE-2026-39821 and
# CVE-2026-46600, upgrades grpc-go for GHSA-hrxh-6v49-42gf, and upgrades
# kin-openapi for GHSA-r277-6w6q-xmqw.
# Current apk minimums: c-ares CVE-2026-33630 is fixed in 1.34.8-r0;
# OpenSSL CVE-2026-14456 is fixed in 3.5.8-r0; PostgreSQL client library
# findings reported against 18.4-r0 are fixed in 18.5-r0.
RUN setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp \
    && apk add --no-cache \
        "c-ares>=1.34.8-r0" \
        "libcrypto3>=3.5.8-r0" \
        "libecpg>=18.5-r0" \
        "libpq>=18.5-r0" \
        "libssl3>=3.5.8-r0" \
        "openssl>=3.5.8-r0"

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
COPY docker/Caddyfile docker/Caddyfile.security-errors docker/healthcheck-app.sh docker/start-production.sh docker/start-worker.sh docker/start-scheduler.sh ./docker/
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
