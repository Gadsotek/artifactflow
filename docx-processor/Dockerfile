FROM dunglas/frankenphp:builder-php8.5-alpine@sha256:862bce4c7efe77337d8f8170a262b639e1787141ffe70ae0edd9ec6ef96c6a99 AS frankenphp-security-builder

ENV PATH="/usr/local/go/bin:${PATH}"

WORKDIR /go/src/app/caddy

RUN go version | grep -E '^go version go1\.26\.7[[:space:]]' \
    && go get golang.org/x/crypto@v0.55.0 \
    && go get google.golang.org/grpc@v1.83.1 \
    && go get github.com/getkin/kin-openapi@v0.144.0 \
    && go mod tidy

WORKDIR /go/src/app/caddy/frankenphp

RUN GOBIN=/usr/local/bin ../../go.sh install \
        -ldflags "-w -s -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP v1.12.6 PHP $PHP_VERSION Caddy' -X 'github.com/caddyserver/caddy/v2.CustomBinaryName=frankenphp' -X 'github.com/caddyserver/caddy/v2/modules/caddyhttp.ServerHeader=FrankenPHP Caddy'" \
        -buildvcs=true \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'google\.golang\.org/grpc[[:space:]]+v1\.83\.1' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'github\.com/getkin/kin-openapi[[:space:]]+v0\.144\.0' \
    && go version -m /usr/local/bin/frankenphp \
        | grep -E 'golang\.org/x/crypto[[:space:]]+v0\.55\.0'

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
        tzdata=2026c-r0 \
        util-linux=2.42.1-r0 \
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

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["php", "/srv/docx-processor/healthcheck.php"]

CMD ["/srv/docx-processor/start.sh"]
