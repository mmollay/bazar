# Multi-stage Dockerfile for Bazar Marketplace
# Stage 1: Build frontend assets
FROM node:18-alpine AS frontend-builder

WORKDIR /app

# Copy package files
COPY bazar-marketplace/package*.json ./

# Install dependencies
RUN npm ci --only=production && npm cache clean --force

# Copy frontend source
COPY frontend/ ./frontend/
COPY bazar-marketplace/webpack.config.js ./

# Build frontend assets
RUN npm run build

# Stage 2: PHP dependencies
FROM composer:2 AS php-dependencies

WORKDIR /app

# Copy composer files
COPY bazar-marketplace/composer.json bazar-marketplace/composer.lock* ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Stage 3: Production PHP runtime
FROM php:8.2-fpm-alpine AS production

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    bash \
    mysql-client \
    redis \
    imagemagick \
    imagemagick-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libzip-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    && rm -rf /var/cache/apk/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo_mysql \
        mysqli \
        zip \
        intl \
        mbstring \
        xml \
        opcache \
        bcmath \
        exif

# Install Redis extension
RUN pecl install redis imagick \
    && docker-php-ext-enable redis imagick

# Create application user
RUN adduser -D -s /bin/bash www-data

# Set working directory
WORKDIR /var/www/html

# Copy PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Copy Nginx configuration
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Copy supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy application code
COPY --chown=www-data:www-data backend/ ./backend/
COPY --chown=www-data:www-data frontend/ ./frontend/
COPY --chown=www-data:www-data index.html ./
COPY --chown=www-data:www-data manifest.json ./
COPY --chown=www-data:www-data sw*.js ./

# Copy built frontend assets
COPY --from=frontend-builder --chown=www-data:www-data /app/dist/ ./public/dist/

# Copy PHP dependencies
COPY --from=php-dependencies --chown=www-data:www-data /app/vendor/ ./vendor/

# Create required directories
RUN mkdir -p \
    /var/www/html/uploads/articles \
    /var/www/html/uploads/temp \
    /var/www/html/logs \
    /var/www/html/cache \
    /var/run/nginx \
    /var/log/supervisor \
    && chown -R www-data:www-data \
        /var/www/html/uploads \
        /var/www/html/logs \
        /var/www/html/cache \
        /var/run/nginx

# Set proper permissions
RUN find /var/www/html -type f -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && chmod -R 755 /var/www/html/uploads \
    && chmod -R 755 /var/www/html/logs

# Health check
COPY docker/healthcheck.sh /usr/local/bin/healthcheck
RUN chmod +x /usr/local/bin/healthcheck
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD /usr/local/bin/healthcheck

# Expose ports
EXPOSE 80 9000

# Use supervisor to manage processes
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# Development stage
FROM production AS development

# Install Xdebug for development
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Copy development PHP configuration
COPY docker/php/php-dev.ini /usr/local/etc/php/

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install development dependencies
RUN composer install --optimize-autoloader

# Development entrypoint
COPY docker/entrypoint-dev.sh /usr/local/bin/entrypoint-dev
RUN chmod +x /usr/local/bin/entrypoint-dev

CMD ["/usr/local/bin/entrypoint-dev"]