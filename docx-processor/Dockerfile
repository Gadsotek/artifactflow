FROM ubuntu:26.04@sha256:2260313b31c8c011cd2eebe728008efac1b3982be73eb71348ea2648d2c0e09b

ARG TARGETARCH
ARG DEBIAN_FRONTEND=noninteractive
ARG LIBREOFFICE_VERSION=26.2.5
ARG LIBREOFFICE_AMD64_SHA256=2f03bfb2ac9f33ea7c77331b4b7a23300fb0ed7443566046bf8b5bc51c1bed1e
ARG LIBREOFFICE_ARM64_SHA256=0e72aa19d216f54100389b8b7840e2d1212470d88fddddb1dae9993d06d7e4ec

ENV HOME=/tmp \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

RUN unlink /usr/bin/pebble \
    && apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        adduser ca-certificates curl fonts-dejavu-core libcairo2 libcups2t64 libdbus-1-3 \
        libfontconfig1 libfreetype6 libglib2.0-0t64 libnss3 libx11-6 libx11-xcb1 \
        libxext6 libxinerama1 php8.5-cli php8.5-xml php8.5-zip socat util-linux \
    && platform_arch="${TARGETARCH:-$(dpkg --print-architecture)}" \
    && case "${platform_arch}" in \
        amd64) lo_directory=x86_64; lo_archive_arch=x86-64; lo_sha="${LIBREOFFICE_AMD64_SHA256}" ;; \
        arm64) lo_directory=aarch64; lo_archive_arch=aarch64; lo_sha="${LIBREOFFICE_ARM64_SHA256}" ;; \
        *) echo "Unsupported DOCX processor architecture: ${platform_arch}" >&2; exit 1 ;; \
       esac \
    && archive="LibreOffice_${LIBREOFFICE_VERSION}_Linux_${lo_archive_arch}_deb.tar.gz" \
    && curl --fail --location --proto '=https' --tlsv1.2 \
        "https://download.documentfoundation.org/libreoffice/stable/${LIBREOFFICE_VERSION}/deb/${lo_directory}/${archive}" \
        --output "/tmp/${archive}" \
    && echo "${lo_sha}  /tmp/${archive}" | sha256sum -c - \
    && mkdir /tmp/libreoffice-debs \
    && tar -xzf "/tmp/${archive}" -C /tmp/libreoffice-debs \
        --wildcards --strip-components=2 '*/DEBS/*.deb' \
    && (dpkg -i /tmp/libreoffice-debs/*.deb || apt-get install -y --no-install-recommends --fix-broken) \
    && dpkg -i /tmp/libreoffice-debs/*.deb \
    && apt-get purge -y --auto-remove curl \
    && apt-get clean \
    && find /var/lib/apt/lists -type f -delete \
    && rm -f /tmp/libreoffice-debs/*.deb "/tmp/${archive}" \
    && rmdir /tmp/libreoffice-debs \
    && addgroup --system --gid 10004 docx-processor \
    && adduser --system --uid 10004 --ingroup docx-processor --no-create-home --shell /usr/sbin/nologin docx-processor \
    && mkdir -p /srv/docx-processor /run/artifactflow/docx-processor \
    && chown docx-processor:docx-processor /run/artifactflow/docx-processor

COPY src /srv/docx-processor/src
COPY public /srv/docx-processor/public
COPY start.sh healthcheck.php /srv/docx-processor/

RUN chmod 0444 /srv/docx-processor/src/DocxProcessor.php /srv/docx-processor/public/index.php \
    && chmod 0555 /srv/docx-processor/start.sh /srv/docx-processor/healthcheck.php

USER docx-processor

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["php", "/srv/docx-processor/healthcheck.php"]

CMD ["/srv/docx-processor/start.sh"]
