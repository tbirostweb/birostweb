#!/bin/sh
set -e

# Port dynamique pour Apache (Dokploy peut injecter $PORT ; défaut 8080).
LISTEN_PORT=${PORT:-8080}
echo "Configuration d'Apache pour écouter sur le port ${LISTEN_PORT}"

sed -i "s/Listen 80/Listen ${LISTEN_PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${LISTEN_PORT}>/g" /etc/apache2/sites-available/*.conf

exec "$@"
