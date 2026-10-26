# syntax=docker/dockerfile:1.7

FROM php:8.5.12-cli-trixie@sha256:ce5d49625b9c50020dc1ce3c9cf4cdfa31cba31b6651e72daa3e6f5a010d234a AS php-base

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
FROM php:8.5.12-cli-alpine3.24@sha256:eef5baa8671b37f2909d4b2f1849ea663df6ff4fe8936f102208ce54f1e32e01 AS image-parser

# The pinned base digest freezes its Alpine packages. Patch the OpenSSL,
# Brotli, and curl findings (CVE-2026-14456, CVE-2026-111497, CVE-2026-92392)
# with targeted minimums; the published image digest and SBOM identify the result.
RUN apk add --no-cache --virtual .image-parser-build-deps \
        $PHPIZE_DEPS \
        libjpeg-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" exif gd \
    && apk add --no-cache \
        "brotli-libs>=1.2.1-r0" \
        ca-certificates \
        "c-ares>=1.34.8-r0" \
        "curl>=8.23.0-r0" \
        "libcurl>=8.23.0-r0" \
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

# Keep the Go toolchain separate from the pinned PHP/FrankenPHP builder so
# standard-library security updates do not require changing the PHP runtime.
FROM golang:1.27.3-alpine3.24@sha256:f2c872bcee949b7be9c490efd02b48a8a2fbf947cdf533f1c58525ec9d8170fd AS go-toolchain

# FrankenPHP 1.13.2 requires Go 1.27. Rebuild with the patched compiler,
# preserving upstream module versions and verifying the compiled dependencies.
FROM dunglas/frankenphp:builder-php8.5-alpine@sha256:3b672209c1201d4c980d4aa76b962171fdb063b47e0108c50d61331488d23d5f AS frankenphp-security-builder

COPY --from=go-toolchain /usr/local/go /opt/go

ENV GOROOT=/opt/go \
    GOTOOLCHAIN=local \
    PATH="/opt/go/bin:${PATH}"

WORKDIR /go/src/app/caddy

RUN go version | grep -E '^go version go1\.27\.3[[:space:]]' \
    && go mod tidy

WORKDIR /go/src/app/caddy/frankenphp

RUN GOBIN=/usr/local/bin ../../go.sh install \
        -ldflags "-w -s -extldflags '-Wl,-z,stack-size=0x80000' -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP v1.13.2 PHP $PHP_VERSION Caddy' -X 'github.com/caddyserver/caddy/v2.CustomBinaryName=frankenphp' -X 'github.com/caddyserver/caddy/v2/modules/caddyhttp.ServerHeader=FrankenPHP Caddy'" \
        -buildvcs=true \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E '^/usr/local/bin/frankenphp: go1\.27\.3$' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'google\.golang\.org/grpc[[:space:]]+v1\.84\.0' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'github\.com/getkin/kin-openapi[[:space:]]+v0\.151\.0' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'golang\.org/x/crypto[[:space:]]+v0\.58\.0' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'golang\.org/x/net[[:space:]]+v0\.61\.0'

FROM dunglas/frankenphp:1-php8.5-alpine@sha256:f4947926b4c5070176be7c72b03939e045f7ae9d249a3219cf9708bd5e6d0ff2 AS production

COPY --from=frankenphp-security-builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

# The base image is pinned by digest, which freezes the OS packages it shipped.
# Rather than a blanket `apk upgrade`, patch only packages Trivy flags. The
# minimum-version constraint below intentionally permits a later patched Alpine
# revision, so this layer is not byte-for-byte reproducible from the base digest
# alone; the release image digest, SBOM, and provenance identify the exact output.
# The source-rebuilt binary uses Go 1.27.3 for CVE-2026-111943 and retains
# upstream's newer x/net, x/crypto, gRPC, and kin-openapi security fixes.
# Current apk minimums: c-ares CVE-2026-33630 is fixed in 1.34.8-r0;
# OpenSSL CVE-2026-14456 is fixed in 3.5.8-r0; PostgreSQL client library
# findings reported against 18.4-r0 are fixed in 18.5-r0;
# Brotli CVE-2026-111497 is fixed in 1.2.1-r0;
# curl/libcurl CVE-2026-92392 is fixed in 8.23.0-r0.
RUN setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp \
    && apk add --no-cache \
        "brotli-libs>=1.2.1-r0" \
        "c-ares>=1.34.8-r0" \
        "curl>=8.23.0-r0" \
        "libcrypto3>=3.5.8-r0" \
        "libcurl>=8.23.0-r0" \
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
COPY LICENSE THIRD_PARTY_NOTICES.md ./
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
