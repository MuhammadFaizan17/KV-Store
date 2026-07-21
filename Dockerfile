FROM php:8.2-fpm

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    sqlite3 \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql pdo_sqlite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application
COPY . /app

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-security-blocking

# Set permissions
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
