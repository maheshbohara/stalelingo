ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
	&& apt-get install -y --no-install-recommends git subversion unzip zip rsync mariadb-client libzip-dev \
	&& docker-php-ext-install mysqli pdo_mysql zip \
	&& pecl install pcov \
	&& docker-php-ext-enable pcov \
	&& rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
		echo 'memory_limit=1G'; \
		echo 'pcov.enabled=1'; \
		echo 'pcov.directory=/app/includes'; \
	} > /usr/local/etc/php/conf.d/zz-dev.ini \
	&& git config --system --add safe.directory '*'

WORKDIR /app
