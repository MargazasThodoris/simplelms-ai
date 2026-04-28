# syntax=docker/dockerfile:1.7
###############################################################################
# TalentLMS AI — Multi-stage Dockerfile
# Stage 1: composer deps  →  Stage 2: assets  →  Stage 3: production image
###############################################################################

###############################################################################
# Stage 1 — PHP dependencies
###############################################################################
FROM php:8.3-fpm-alpine AS php-deps

RUN apk add --no-cache \
    git unzip curl libpng-dev libjpeg-turbo-dev libwebp-dev \
    libzip-dev icu-dev oniguruma-dev postgresql-dev \
    ffmpeg ghostscript

RUN docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j$(nproc) \
        pdo pdo_pgsql pgsql \
        zip intl mbstring opcache \
        gd exif pcntl bcmath

# Redis extension
RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-dev

###############################################################################
# Stage 2 — Node / asset build
###############################################################################
FROM node:22-alpine AS assets

WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY assets/ ./assets/
COPY webpack.config.js ./
RUN npm run build

###############################################################################
# Stage 3 — Production image
###############################################################################
FROM php:8.3-fpm-alpine AS production

LABEL org.opencontainers.image.title="TalentLMS AI Platform"
LABEL org.opencontainers.image.description="PHP 8.3 / Symfony 7.2 LMS with Agentic AI"

# Install runtime dependencies only
RUN apk add --no-cache \
    libpng libjpeg-turbo libwebp libzip icu oniguruma libpq \
    ffmpeg ghostscript nginx supervisor curl

# PHP extensions (re-install from source in this stage)
RUN docker-php-ext-install -j$(nproc) \
        pdo pdo_pgsql pgsql \
        zip intl mbstring opcache gd exif pcntl bcmath
RUN pecl install redis && docker-php-ext-enable redis

# PHP production config
COPY infrastructure/docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY infrastructure/docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY infrastructure/docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Nginx config
COPY infrastructure/docker/nginx.conf /etc/nginx/nginx.conf

# Supervisor config (manages nginx + php-fpm)
COPY infrastructure/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

# Copy application code
COPY --chown=www-data:www-data . .

# Copy compiled deps and assets
COPY --from=php-deps --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets   --chown=www-data:www-data /app/public/build ./public/build

# Generate optimised autoloader, warm caches
RUN composer dump-autoload --optimize --no-dev \
 && php bin/console cache:warmup --env=prod \
 && chown -R www-data:www-data var/

EXPOSE 80 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/api/v1/health || exit 1

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
