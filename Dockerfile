FROM composer:2 AS composer

FROM php:8.5-cli

ARG UID=1000
ARG GID=1000

# git and unzip are used by Composer to fetch and extract packages.
# libonig-dev/libzip-dev are the build dependencies of the mbstring and zip
# extensions; mbstring is required by PHPUnit 9 and Symfony, zip lets Composer
# extract archives natively.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        libonig-dev \
        libzip-dev; \
    docker-php-ext-install -j"$(nproc)" mbstring zip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

# A real passwd entry for the mapped UID so that HOME resolves and tools such as
# Composer or PHPUnit have a writable home directory.
RUN set -eux; \
    if ! getent group "${GID}" >/dev/null; then groupadd --gid "${GID}" app; fi; \
    if ! getent passwd "${UID}" >/dev/null; then \
        useradd --uid "${UID}" --gid "${GID}" --create-home --home-dir /home/app --shell /bin/bash app; \
    fi; \
    mkdir -p /composer; \
    chown -R "${UID}:${GID}" /composer /home/app

# Deprecations are the whole point of this image: report everything.
RUN { \
        echo 'error_reporting = E_ALL'; \
        echo 'display_errors = On'; \
        echo 'display_startup_errors = On'; \
        echo 'memory_limit = -1'; \
    } > /usr/local/etc/php/conf.d/zz-dev.ini

ENV COMPOSER_HOME=/composer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    HOME=/home/app

WORKDIR /app

CMD ["bash"]
