# ==========================================
# Stage 1: Build Frontend Assets (Vite)
# ==========================================
# Node is build-only (Vite); it never ships in the runtime image. The floating 22-alpine tag
# tracks the latest 22.x patch (needs >= 22.23.4). Deploy with "Clear build cache & deploy"
# so Render does not reuse a stale cached layer. Could not verify an exact 22.23.x tag when this was written.
FROM node:22-alpine AS node_builder

WORKDIR /app

# Copy package files and install dependencies
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts || npm install --ignore-scripts

# Copy frontend source and build assets
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# ==========================================
# Stage 2: Production PHP + Nginx Runtime
# ==========================================
# Security: PHP2Shell (CVE-2026-109618, CVE-2026-109619) is fixed in PHP 8.3.36. The exact tag
# php:8.3.36-fpm-alpine could not be verified on Docker Hub when this was written, so the floating
# 8.3 tag is used and the build below FAILS if it resolves to < 8.3.36. The deploy MUST use
# "Clear build cache & deploy" in Render, otherwise a cached older base layer may be reused.
FROM php:8.3-fpm-alpine

LABEL maintainer="ViLoCare Team"
LABEL description="ViLoCare Backend Container for Render"

# Refresh Alpine packages first so CA certificates are current (eMudhra certificate advisory).
RUN apk upgrade --no-cache

# Show the deployed PHP version in the Render build log and refuse to build anything below 8.3.36.
RUN php -v \
    && php -r 'if (version_compare(PHP_VERSION, "8.3.36", "<")) { fwrite(STDERR, "PHP " . PHP_VERSION . " is below 8.3.36 (PHP2Shell fix). Use Clear build cache & deploy.\n"); exit(1); }'

# Install system dependencies and build libraries
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    bash \
    gettext \
    git \
    libpq-dev \
    postgresql-client \
    sqlite-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        pdo_sqlite \
        pdo_mysql \
        bcmath \
        gd \
        intl \
        zip \
        opcache \
        pcntl \
        exif \
    && rm -rf /var/cache/apk/*

# Copy Composer binary from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy Composer dependency definitions for efficient layer caching
COPY composer.json composer.lock ./

# Install production PHP dependencies (without running scripts yet)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# Copy application source code
COPY . .

# Copy compiled frontend assets from Stage 1
COPY --from=node_builder /app/public/build ./public/build

# Finish Composer dump-autoload and package discovery
RUN composer dump-autoload --optimize --no-dev

# Copy configuration files
RUN mkdir -p /etc/nginx/templates /etc/nginx/http.d /etc/supervisor/conf.d

COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# Configure user groups and directory permissions
RUN addgroup -S www-data www-data 2>/dev/null || true \
    && adduser -S -D -H -G www-data www-data 2>/dev/null || true \
    && addgroup nginx www-data 2>/dev/null || true \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/www/html/storage/framework/cache/data \
               /var/www/html/storage/framework/sessions \
               /var/www/html/storage/framework/views \
               /var/www/html/storage/logs \
               /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Render injects PORT (default: 10000)
EXPOSE 10000

# Set entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
