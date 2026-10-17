# Default target is `dev` (the last stage). The server cannot accept
# connections yet, so the image you get from `docker build .` runs the checks.
#
#   docker build --target dev -t phpserver:dev .
#   docker build --target runtime -t phpserver:runtime .
#
# `runtime` is a placeholder until M5. `bin/serverd` prints "not implemented"
# and exits 1. The entrypoint, user, ports, and PHPSERVER_* variables stay as
# they will be when the process really listens.

FROM php:8.4-cli-alpine AS php-base

# posix is compiled into php:8.4-cli-alpine. pcntl and sockets are not.
RUN set -eux; \
    apk add --no-cache --virtual .build-deps ${PHPIZE_DEPS} linux-headers; \
    docker-php-ext-install -j"$(nproc)" pcntl sockets; \
    apk del .build-deps; \
    php -m | grep -qx pcntl; \
    php -m | grep -qx posix; \
    php -m | grep -qx sockets

FROM php-base AS deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# The tree is not a git checkout inside the image, so Composer cannot guess a version.
ENV COMPOSER_ROOT_VERSION=dev-main

# Install before the rest of the tree so dependency layers stay cached.
COPY composer.json composer.lock ./

RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

FROM php-base AS runtime

# Placeholder until M5: bin/serverd exits 1 with "not implemented".
# No HEALTHCHECK. Kubernetes probes on the admin port (/healthz, /readyz)
# arrive in M6; this image does not serve them yet.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ROOT_VERSION=dev-main

COPY composer.json composer.lock ./

RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress --no-scripts \
    && rm -rf /usr/bin/composer /root/.composer

COPY bin bin
COPY config config
COPY src src

RUN addgroup -g 10001 -S phpserver \
    && adduser -S -D -H -u 10001 -G phpserver -h /app phpserver \
    && chown -R phpserver:phpserver /app

USER 10001

# Container port publishing cannot reach 127.0.0.1. These override the
# example file (127.0.0.1:9000 and 127.0.0.1:9100) once serverd loads config.
ENV PHPSERVER_LISTEN=0.0.0.0:9000 \
    PHPSERVER_ADMIN_LISTEN=0.0.0.0:9100

EXPOSE 9000 9100

STOPSIGNAL SIGTERM

ENTRYPOINT ["/app/bin/serverd"]
CMD ["start", "--foreground", "-c", "config/server.echo.php"]

FROM deps AS dev

COPY . .

CMD ["sh", "-c", "composer test && composer analyse && composer cs"]
