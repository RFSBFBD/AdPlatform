FROM php:8.3-fpm

WORKDIR /var/www/backend

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    zip \
    unzip \
    libpq-dev \
    postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# Install official PHP extension installer for precompiled extensions
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
    pdo_pgsql \
    pgsql \
    redis \
    bcmath \
    pcntl \
    intl \
    zip \
    opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy custom php.ini configuration
COPY local.ini /usr/local/etc/php/conf.d/local.ini

EXPOSE 9000

CMD ["php-fpm"]
