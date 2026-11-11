FROM node:22-bookworm-slim@sha256:4b150b48b4116e2a22f2c39cc27025fbf4f153e2078fd3b14d159fdb5c7279da AS assets
WORKDIR /src
COPY package*.json ./
RUN npm ci --ignore-scripts
COPY vite.config.ts tsconfig.json ./
COPY packages/cms packages/cms
RUN npm run build
FROM composer:2@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c AS dependencies
WORKDIR /src
COPY packages/cms packages/cms
COPY apps/starter apps/starter
WORKDIR /src/apps/starter
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader && rm vendor/formaku/cms && cp -a /src/packages/cms vendor/formaku/cms
FROM dunglas/frankenphp:1-php8.5-bookworm@sha256:924b91db4f9aa1e6b1e40b8065ebe4ed6205cce7fe47fa49b55759143f09165d AS runtime
RUN install-php-extensions pdo_pgsql pdo_mysql intl zip pcntl opcache gd exif
WORKDIR /app
COPY --from=dependencies /src/apps/starter /app
COPY --from=assets /src/packages/cms/public /app/vendor/formaku/cms/public
RUN mkdir -p /app/public/vendor/formaku && cp -a /app/vendor/formaku/cms/public/. /app/public/vendor/formaku/ && chown -R www-data:www-data /app/storage /app/bootstrap/cache
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/formaku-entrypoint
USER www-data
ENV SERVER_NAME=:8080
EXPOSE 8080
ENTRYPOINT ["formaku-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
