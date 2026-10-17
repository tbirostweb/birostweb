#!/bin/sh
set -e

# Port dynamique pour Apache (Dokploy peut injecter $PORT ; défaut 8080).
# Lu par Apache via ${LISTEN_PORT} (ports.conf / VirtualHost) : aucune écriture de config au démarrage.
export LISTEN_PORT=${PORT:-8080}
echo "Apache écoute sur le port ${LISTEN_PORT}"

exec "$@"
