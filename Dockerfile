FROM php:8.2-cli-alpine

RUN apk add --no-cache sqlite-dev sqlite mysql-client mariadb-connector-c-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql

WORKDIR /var/www/html

COPY . /var/www/html

COPY docker/dev-entrypoint.sh /usr/local/bin/dev-entrypoint.sh
RUN chmod +x /usr/local/bin/dev-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/dev-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000", "router.php"]
