# Dedicated XLSX parser/projection image. It receives one bounded workbook over
# a private Unix socket and has no application source, storage, or credentials.
FROM node:26-alpine@sha256:aadf416b2cdce311a8811ba3f0608a61b77dbf997500e2eafe781b51f6a0b019 AS xlsx-processor-spike

ENV HOME=/tmp/home \
    NODE_ENV=production \
    NPM_CONFIG_CACHE=/tmp/npm-cache

RUN apk upgrade --no-cache \
    && addgroup -S -g 10003 xlsx-spike \
    && adduser -S -D -H -u 10003 -G xlsx-spike -s /sbin/nologin xlsx-spike \
    && mkdir -p /srv/xlsx-processor-spike \
    && chown xlsx-spike:xlsx-spike /srv/xlsx-processor-spike

WORKDIR /srv/xlsx-processor-spike

COPY package.json package-lock.json ./

RUN npm ci --ignore-scripts --omit=dev \
    && npm cache clean --force

COPY --chown=xlsx-spike:xlsx-spike src ./src
COPY --chown=xlsx-spike:xlsx-spike test ./test
COPY --chown=xlsx-spike:xlsx-spike README.md ./README.md
COPY --chown=xlsx-spike:xlsx-spike healthcheck.cjs ./healthcheck.cjs
COPY --chown=xlsx-spike:xlsx-spike licenses ./licenses

USER xlsx-spike

CMD ["npm", "test"]

FROM xlsx-processor-spike AS xlsx-processor-spike-service

USER root

RUN npm uninstall --global npm

USER xlsx-spike

HEALTHCHECK --interval=30s --timeout=3s --start-period=30s --retries=3 \
    CMD ["node", "healthcheck.cjs"]

CMD ["node", "src/start-server.cjs"]
