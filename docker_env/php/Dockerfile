FROM php:8.5-fpm

# Set environment variables
ENV TZ=UTC

# Install system dependencies and PHP extensions
# libreoffice-writer-nogui — конвертация договоров .doc → .docx (PurchaseContractConverter):
# нужен только Writer в режиме --headless, без Calc, Impress и графического интерфейса
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    librabbitmq-dev \
    git \
    unzip \
    libreoffice-writer-nogui \
    && docker-php-ext-install pdo pgsql pdo_pgsql zip \
    && pecl install xdebug amqp \
    && docker-php-ext-enable xdebug amqp \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

EXPOSE 9000
CMD ["php-fpm"]
