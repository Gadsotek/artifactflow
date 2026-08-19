# Disabled, non-production PDF extraction candidate. This separate build
# context is used only by `make pdf-processor-spike-test`.
FROM php:8.5.9-cli-alpine3.24@sha256:0554eb53778b5316f6b9a3447c9dfa3cf2141c0c02ff816c42cdc9aa240a34aa AS pdf-processor-spike-builder

ARG PDFBOX_VERSION=3.0.8
ARG PDFBOX_SHA512=768847238f683568507bf73570a2b6fedcbe58b25c7b4f97fba536ba110b290fe96ba065aed58629d41fb94857d76bc1978c2f31d294b553c69f287f71ee9600

RUN apk add --no-cache \
        ca-certificates=20260611-r0 \
        openjdk21-jdk=21.0.12_p8-r0 \
    && wget -q "https://downloads.apache.org/pdfbox/${PDFBOX_VERSION}/pdfbox-app-${PDFBOX_VERSION}.jar" -O /opt/pdfbox-app.jar \
    && echo "${PDFBOX_SHA512}  /opt/pdfbox-app.jar" | sha512sum -c -

COPY src/main/java /src

RUN mkdir -p /opt/pdf-spike-classes \
    && javac --release 21 -cp /opt/pdfbox-app.jar -d /opt/pdf-spike-classes \
        /src/app/artifactflow/pdfspike/Main.java

FROM php:8.5.9-cli-alpine3.24@sha256:0554eb53778b5316f6b9a3447c9dfa3cf2141c0c02ff816c42cdc9aa240a34aa AS pdf-processor-spike

ARG PDFBOX_VERSION=3.0.8
ARG PDFBOX_SHA512=768847238f683568507bf73570a2b6fedcbe58b25c7b4f97fba536ba110b290fe96ba065aed58629d41fb94857d76bc1978c2f31d294b553c69f287f71ee9600

RUN apk add --no-cache \
        ca-certificates=20260611-r0 \
        openjdk21-jre-headless=21.0.12_p8-r0 \
    && addgroup -S -g 10002 pdf-spike \
    && adduser -S -D -H -u 10002 -G pdf-spike -s /sbin/nologin pdf-spike

COPY --from=pdf-processor-spike-builder /opt/pdfbox-app.jar /opt/pdfbox-app.jar
COPY --from=pdf-processor-spike-builder /opt/pdf-spike-classes /srv/pdf-processor-spike/classes
COPY README.md /srv/pdf-processor-spike/README.md

WORKDIR /srv/pdf-processor-spike

USER pdf-spike

ENTRYPOINT ["java", "-Xms32m", "-Xmx384m", "-XX:MaxMetaspaceSize=96m", "-cp", "/opt/pdfbox-app.jar:/srv/pdf-processor-spike/classes", "app.artifactflow.pdfspike.Main"]

CMD ["self-test"]

# Authenticated service shell. Compose wires it only into the isolated e2e
# profile until the production hostile-corpus and deployment gates are accepted.
FROM pdf-processor-spike AS pdf-processor-service

USER root

COPY src/PdfProcessor.php /srv/pdf-processor-spike/src/PdfProcessor.php
COPY public /srv/pdf-processor-spike/public
COPY start.sh healthcheck.php /srv/pdf-processor-spike/

RUN chmod 0444 /srv/pdf-processor-spike/src/PdfProcessor.php /srv/pdf-processor-spike/public/index.php \
    && chmod 0555 /srv/pdf-processor-spike/start.sh /srv/pdf-processor-spike/healthcheck.php

USER pdf-spike

ENTRYPOINT []

HEALTHCHECK --interval=30s --timeout=15s --start-period=10s --retries=3 \
    CMD ["php", "/srv/pdf-processor-spike/healthcheck.php"]

CMD ["/srv/pdf-processor-spike/start.sh"]
