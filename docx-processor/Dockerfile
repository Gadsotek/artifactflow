# Match the app's PHP 8.5.12 base and patched Go 1.27.3 toolchain.
FROM golang:1.27.3-alpine3.24@sha256:f2c872bcee949b7be9c490efd02b48a8a2fbf947cdf533f1c58525ec9d8170fd AS go-toolchain

FROM dunglas/frankenphp:builder-php8.5-alpine@sha256:3b672209c1201d4c980d4aa76b962171fdb063b47e0108c50d61331488d23d5f AS frankenphp-security-builder

COPY --from=go-toolchain /usr/local/go /opt/go
COPY frankenphp-security /opt/artifactflow-frankenphp-security

ENV GOROOT=/opt/go \
    GOTOOLCHAIN=local \
    PATH="/opt/go/bin:${PATH}"

WORKDIR /go/src/app/caddy

# FrankenPHP 1.13.2 requires Go 1.27. Apply the same reviewed go-jose migration
# as the app while retaining its other fixed modules and Go 1.27.3 compiler.
RUN go version | grep -E '^go version go1\.27\.3[[:space:]]' \
    && sh /opt/artifactflow-frankenphp-security/prepare.sh

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
        | grep -E 'golang\.org/x/net[[:space:]]+v0\.61\.0' \
    && sh /opt/artifactflow-frankenphp-security/verify.sh /usr/local/bin/frankenphp

FROM dunglas/frankenphp:1-php8.5-alpine@sha256:f4947926b4c5070176be7c72b03939e045f7ae9d249a3219cf9708bd5e6d0ff2

ENV HOME=/tmp \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

COPY --from=frankenphp-security-builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=frankenphp-security-builder /opt/artifactflow-frankenphp-notices /usr/local/share/licenses/artifactflow-frankenphp

RUN apk add --no-cache \
        "brotli-libs>=1.2.1-r0" \
        ca-certificates=20261020-r0 \
        "c-ares>=1.34.8-r0" \
        "curl>=8.23.0-r0" \
        font-dejavu=2.37-r6 \
        font-liberation=2.1.5-r2 \
        "libcrypto3>=3.5.8-r0" \
        "libcurl>=8.23.0-r0" \
        libreoffice-writer=25.8.7.3-r0 \
        "libssl3>=3.5.8-r0" \
        "openssl>=3.5.8-r0" \
        tzdata=2026e-r0 \
        util-linux=2.42.3-r1 \
    && addgroup -S -g 10004 docx-processor \
    && adduser -S -D -H -u 10004 -G docx-processor -s /sbin/nologin docx-processor \
    && mkdir -p /srv/docx-processor /run/artifactflow/docx-processor \
    && chown docx-processor:docx-processor /run/artifactflow/docx-processor

RUN install-php-extensions \
    pcntl \
    zip

COPY src /srv/docx-processor/src
COPY public /srv/docx-processor/public
COPY start.sh healthcheck.php Caddyfile /srv/docx-processor/
COPY docx-processor.ini /usr/local/etc/php/conf.d/99-docx-processor.ini

RUN chmod 0444 /srv/docx-processor/src/DocxProcessor.php /srv/docx-processor/public/index.php \
    && chmod 0444 /srv/docx-processor/Caddyfile /usr/local/etc/php/conf.d/99-docx-processor.ini \
    && chmod 0555 /srv/docx-processor/start.sh /srv/docx-processor/healthcheck.php

USER docx-processor

HEALTHCHECK --interval=30s --timeout=15s --start-period=10s --retries=3 \
    CMD ["php", "/srv/docx-processor/healthcheck.php"]

CMD ["/srv/docx-processor/start.sh"]
