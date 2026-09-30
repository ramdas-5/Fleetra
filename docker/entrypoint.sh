#!/bin/sh
# =====================================================================
#  Fleetra container entrypoint
#
#  Most container platforms (Render, Koyeb, Fly.io, Railway) inject the
#  port they expect the app to listen on via the $PORT environment
#  variable. Apache defaults to 80, so rewrite its config to match before
#  handing control back to the official apache2-foreground command.
# =====================================================================

set -e

PORT="${PORT:-80}"

if [ "$PORT" != "80" ]; then
    # ports.conf: "Listen 80" -> "Listen $PORT"
    sed -ri "s/^Listen[[:space:]]+[0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf

    # default vhost: <VirtualHost *:80> -> <VirtualHost *:$PORT>
    sed -ri "s/:80>/:${PORT}>/g" /etc/apache2/sites-available/000-default.conf
fi

# Make sure the runtime directories exist and are writable even when a
# volume or a fresh checkout replaced the ones baked into the image.
mkdir -p /var/www/html/logs /var/www/html/uploads/profiles /var/www/html/uploads/buses
chown -R www-data:www-data /var/www/html/logs /var/www/html/uploads 2>/dev/null || true

exec "$@"
