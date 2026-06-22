# console Laravel — nginx + php-fpm (produção, multi-worker). O dev (docker-compose.dev.yml)
# faz override do command com `php artisan serve`, então este CMD só vale em produção.
FROM php:8.4-fpm-alpine
RUN apk add --no-cache git unzip libzip-dev postgresql-dev icu-dev nginx supervisor \
 && docker-php-ext-install pdo_pgsql zip bcmath intl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction \
 # symlink do storage público (mídia) embutido na imagem — sobrevive a recriações do container
 && mkdir -p storage/app/public \
 && ln -sfn /app/storage/app/public /app/public/storage

# Configs de produção: nginx (front) + php-fpm (pool) + php (limites) + supervisor (roda os dois).
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/www.conf         /usr/local/etc/php-fpm.d/www.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/zz-reachyn.ini
COPY docker/supervisord.conf /etc/supervisord.conf

# AUD-017: roda como www-data (non-root). O Laravel escreve em storage/ e bootstrap/cache;
# nginx/php-fpm/supervisor usam /tmp e os caches do nginx — todos precisam ser graváveis.
RUN mkdir -p /tmp/nginx-client /tmp/nginx-proxy /tmp/nginx-fastcgi /tmp/nginx-uwsgi /tmp/nginx-scgi /var/lib/nginx /var/log/nginx \
 && chown -R www-data:www-data /app/storage /app/bootstrap/cache /var/lib/nginx /var/log/nginx /tmp/nginx-client /tmp/nginx-proxy /tmp/nginx-fastcgi /tmp/nginx-uwsgi /tmp/nginx-scgi
USER www-data
EXPOSE 8000
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
