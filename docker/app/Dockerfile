# The application container: PHP-FPM, and the place the integration tests run.
#
# One image for both, on purpose. The tests have to reach the database and Cyrus
# over the same network, with the same trust store and the same PHP build as the
# application — otherwise "the test passes" and "the application works" are two
# different statements.
#
# Nothing is copied in. The project is bind-mounted at /var/www/mailadmin, so an
# edit on the host is live in the container and no rebuild stands between a
# change and a test run.

FROM php:8.3-fpm-bookworm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        libzip-dev \
        unzip; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql opcache; \
    rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/zz-mailadmin.ini
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/mailadmin

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
