# Match the app's patched Go toolchain without changing the PHP/FrankenPHP base.
FROM golang:1.26.9-alpine3.24@sha256:cdfd4fe2da6b225d8b40c6b7a105736e548e83ff56d5d8f9394446eeb5eb84e0 AS go-toolchain

FROM dunglas/frankenphp:builder-php8.5-alpine@sha256:862bce4c7efe77337d8f8170a262b639e1787141ffe70ae0edd9ec6ef96c6a99 AS frankenphp-security-builder

COPY --from=go-toolchain /usr/local/go /opt/go

ENV GOROOT=/opt/go \
    GOTOOLCHAIN=local \
    PATH="/opt/go/bin:${PATH}"

WORKDIR /go/src/app/caddy

# Go 1.26.9 and x/net 0.60.0 fix CVE-2026-78667/CVE-2026-78669/CVE-2026-97031.
# x/net requires x/crypto 0.57.0; retain the other reviewed module fixes.
RUN go version | grep -E '^go version go1\.26\.9[[:space:]]' \
    && go get golang.org/x/crypto@v0.57.0 \
    && go get golang.org/x/net@v0.60.0 \
    && go get google.golang.org/grpc@v1.83.2 \
    && go get github.com/getkin/kin-openapi@v0.144.0 \
    && go mod tidy

WORKDIR /go/src/app/caddy/frankenphp

RUN GOBIN=/usr/local/bin ../../go.sh install \
        -ldflags "-w -s -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP v1.12.6 PHP $PHP_VERSION Caddy' -X 'github.com/caddyserver/caddy/v2.CustomBinaryName=frankenphp' -X 'github.com/caddyserver/caddy/v2/modules/caddyhttp.ServerHeader=FrankenPHP Caddy'" \
        -buildvcs=true \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E '^/usr/local/bin/frankenphp: go1\.26\.9$' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'google\.golang\.org/grpc[[:space:]]+v1\.83\.2' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'github\.com/getkin/kin-openapi[[:space:]]+v0\.144\.0' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'golang\.org/x/crypto[[:space:]]+v0\.57\.0' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'golang\.org/x/net[[:space:]]+v0\.60\.0'

FROM dunglas/frankenphp:1-php8.5-alpine@sha256:214b541fe30aeed2717d54f74149b71b2bdb0089718f1fbb8b43bc170ab00939

ENV HOME=/tmp \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

COPY --from=frankenphp-security-builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

RUN apk add --no-cache \
        ca-certificates=20260611-r0 \
        "c-ares>=1.34.8-r0" \
        font-dejavu=2.37-r6 \
        font-liberation=2.1.5-r2 \
        "libcrypto3>=3.5.8-r0" \
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
