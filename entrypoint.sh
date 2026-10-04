#!/bin/sh
set -e

# Port dynamique (Dokploy peut injecter $PORT ; défaut 8080). Doit être un entier 1024-65535.
LISTEN_PORT=${PORT:-8080}
case "$LISTEN_PORT" in
  ''|*[!0-9]*) echo "PORT invalide : doit être numérique (1024-65535)" >&2; exit 1 ;;
esac
if [ "$LISTEN_PORT" -lt 1024 ] || [ "$LISTEN_PORT" -gt 65535 ]; then
  echo "PORT invalide : hors de 1024-65535" >&2; exit 1
fi

# Apache lit ${APP_PORT} dans ports.conf / le VirtualHost (préparés au build) : aucune écriture dans /etc.
export APP_PORT="$LISTEN_PORT"
echo "Apache écoute sur le port ${APP_PORT}"

# Purge indépendante des visites et de l'utilisation du formulaire.
(while :; do php /var/www/html/purge-contact.php >/dev/null 2>&1; sleep 3600; done) &
exec "$@"
