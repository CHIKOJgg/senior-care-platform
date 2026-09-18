FROM php:8.2-cli-alpine

# Install SQLite and PDO dependencies
RUN apk add --no-cache sqlite-dev sqlite \
    && docker-php-ext-install pdo pdo_sqlite

WORKDIR /var/www/html

COPY . /var/www/html

# Run migrations and seeders on container startup
RUN php database/migrate.php && php database/seed.php

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
