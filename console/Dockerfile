# console Laravel — nginx + php-fpm (produção, multi-worker). O dev (docker-compose.dev.yml)
# faz override do command com `php artisan serve`, então este CMD só vale em produção.
FROM php:8.4-fpm-alpine
RUN apk add --no-cache git unzip libzip-dev postgresql-dev icu-dev nginx supervisor \
    libwebp-dev libjpeg-turbo-dev libpng-dev \
 && docker-php-ext-configure gd --with-webp --with-jpeg \
 && docker-php-ext-install pdo_pgsql zip bcmath intl gd exif
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

# 📦 O VENDOR VEM ANTES DO CÓDIGO — de propósito. Com `COPY . .` primeiro, QUALQUER mudança de
# código (até um comentário num .blade) invalidava a camada e reinstalava o vendor inteiro: minutos
# por deploy e ~1GB de cache novo A CADA BUILD. Em 25 dias de self-contained isso virou 205GB de
# build cache na VPS (84% do disco, medido 2026-07-15). composer.json/lock mudam raro; o código muda
# todo deploy — então eles são camadas separadas.
#
# --no-scripts/--no-autoloader aqui é obrigatório: o post-autoload-dump roda `artisan
# package:discover` e `filament:upgrade`, que precisam do código — que ainda não foi copiado. O
# dump-autoload logo abaixo (já com o código) dispara esses scripts.
#
# composer install resiliente: o codeload do GitHub às vezes devolve HTTP 400 em dist zips
# (incidente transitório) e derruba o build. Tenta com --prefer-dist 2× e, no fim, cai pra
# --prefer-source (clone via git, outro endpoint) — assim instabilidade do GitHub não quebra o deploy.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader \
 || (sleep 10 && composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader) \
 || (sleep 15 && composer install --no-dev --no-interaction --prefer-source --no-scripts --no-autoloader)

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
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
# Health = o endpoint /up nativo do Laravel via nginx local (php -r: a imagem não tem curl).
HEALTHCHECK --interval=30s --timeout=6s --start-period=30s --retries=3 \
  CMD php -r '$c=@file_get_contents("http://127.0.0.1:8000/up");exit($c===false?1:0);' || exit 1
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
