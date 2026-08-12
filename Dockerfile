# syntax=docker/dockerfile:1.7

# FrankenPHP 2 doesn't exist yet (confirmed against Docker Hub + GitHub
# releases 2026-08-12) -- latest stable is 1.12.7. Floating on the 1.x line
# still gets patch releases on the current PHP 8.5 build.
ARG FRANKENPHP_VERSION=1
ARG PHP_VERSION=8.5

# ---- base: OS packages + PHP extensions FrankenPHP needs to run this app.
# Shared by both stages below and cached as one layer across deploys -- it
# only rebuilds when this file changes, not when application code changes.
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS base

WORKDIR /app

RUN install-php-extensions \
        apcu \
        intl \
        opcache \
        pdo_pgsql \
        redis \
        sockets \
        zip

COPY Caddyfile /etc/caddy/Caddyfile
COPY docker/php.ini $PHP_INI_DIR/conf.d/app.ini

# ---- build: composer + asset-mapper. Needs the full toolchain (composer,
# dev deps for autoload discovery) but none of it ships in the final image.
# No Node/npm stage needed -- asset-mapper compiles importmap-managed assets
# directly, unlike Encore.
FROM base AS build

RUN install-php-extensions @composer

# Everything in this stage runs as root (no USER switch). Composer detects
# that and silently disables all plugins in --no-interaction mode unless
# told otherwise -- including symfony/runtime's plugin, which is what
# generates the runtime bootstrap glue bin/console needs. Without this,
# bin/console fails with "Symfony Runtime is missing" right after
# dump-autoload, even though `composer install` itself reports success.
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.cache/composer \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

# cache:clear (which warms up by default) must run before asset-map:compile:
# survos/js-twig-bundle's FosRoutingCacheWarmer is what generates
# var/js_twig_bundle/generated/fos_routes.js, and asset-map:compile fails
# without it already on disk (known recurring gap on every upgrade).
RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && php bin/console cache:clear --env=prod --no-debug \
    && php bin/console importmap:install --env=prod \
    && php bin/console asset-map:compile --env=prod

# ---- prod: just the base image + the built app. No composer, no .git, no
# build-time composer cache -- keeps the runtime image to app + vendor only.
FROM base AS prod

ENV APP_ENV=prod APP_DEBUG=0

# --chown here sets ownership during the copy itself (one pass) instead of
# a separate `RUN chown -R` walking the whole tree afterward (two passes) --
# meaningfully faster once var/cache is populated with thousands of warmed
# cache/container files.
COPY --from=build --chown=www-data:www-data /app /app

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
