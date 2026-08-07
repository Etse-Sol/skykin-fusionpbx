# SkyKin FusionPBX / Agent Dashboard — web image
# Default compose runs FreeSWITCH as a sibling container (ESL_HOST=freeswitch).
# Hybrid mode can still point ESL/WS at the host/VM.

FROM php:8.2-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev \
        unzip \
        curl \
    && docker-php-ext-install pdo pdo_pgsql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

COPY docker/nginx.conf /etc/nginx/sites-available/skykin.conf
RUN ln -s /etc/nginx/sites-available/skykin.conf /etc/nginx/sites-enabled/skykin.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/skykin.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/fusionpbx
COPY --chown=www-data:www-data . /var/www/fusionpbx

RUN mkdir -p /etc/fusionpbx /var/log/nginx \
    && chown -R www-data:www-data /var/www/fusionpbx/app/agent_dashboard \
    && touch /var/www/fusionpbx/app/agent_dashboard/skykin_local.db \
    && chown www-data:www-data /var/www/fusionpbx/app/agent_dashboard/skykin_local.db

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["web"]
