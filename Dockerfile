# =====================================================================
#  Fleetra — Smart Bus & Transport Management System
#  Production container image (PHP 8.3 + Apache).
#
#  Used by container platforms such as Render, Koyeb, Fly.io or Railway.
#  Shared hosts (InfinityFree, Byet.host, TinkerHost) do NOT need this —
#  they run PHP directly and you just upload the files over FTP.
#
#  Build:  docker build -t fleetra .
#  Run:    docker run -p 8080:80 \
#            -e DB_HOST=... -e DB_NAME=... -e DB_USER=... -e DB_PASS=... \
#            -e APP_ENV=production fleetra
# =====================================================================

FROM php:8.3-apache

# --- PHP extensions the application actually uses ---------------------
#   pdo_mysql  — every database query goes through PDO
#   mbstring   — mb_strlen / mb_substr / mb_stripos in the helpers
#   fileinfo   — upload MIME sniffing (ships enabled in the base image)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libonig-dev \
 && docker-php-ext-install -j"$(nproc)" mbstring pdo_mysql \
 && rm -rf /var/lib/apt/lists/*

# --- Apache modules ---------------------------------------------------
#   rewrite — clean URLs / future routing
#   headers — the security headers set in the root .htaccess
RUN a2enmod rewrite headers

# The base image ships `AllowOverride None` for /var/www, which would make
# every Fleetra .htaccess a no-op. The app relies on those rules to protect
# config/, includes/, database/, logs/ and to stop uploads executing.
RUN sed -ri 's/^\s*AllowOverride\s+None/    AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# --- Application ------------------------------------------------------
COPY . /var/www/html/

# Writable runtime directories. .env (if you ship one) stays read-only and
# is blocked from the browser by the root .htaccess.
RUN mkdir -p logs uploads/profiles uploads/buses \
 && chown -R www-data:www-data logs uploads \
 && chmod -R 775 logs uploads

# Honour the platform-provided $PORT (Render, Koyeb, Fly set it); fall back
# to 80 for a plain `docker run`.
COPY docker/entrypoint.sh /usr/local/bin/fleetra-entrypoint
RUN chmod +x /usr/local/bin/fleetra-entrypoint

EXPOSE 80

ENTRYPOINT ["fleetra-entrypoint"]
CMD ["apache2-foreground"]
