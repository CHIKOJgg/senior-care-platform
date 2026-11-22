FROM php:8.2-cli-alpine

# MySQL client libraries + PDO MySQL only.
RUN apk add --no-cache mariadb-connector-c-dev \
    && docker-php-ext-install pdo pdo_mysql

WORKDIR /var/www/html

COPY . /var/www/html

EXPOSE 8000
