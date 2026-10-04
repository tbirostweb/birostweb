# ============================================================
#  birostweb.fr — image de production (PHP + Apache, non-root)
#  Le formulaire de contact envoie par SMTP (PHPMailer), donc on a besoin
#  d'un back-end PHP. HTTPS/TLS est géré en amont par Traefik (Dokploy).
#  Images épinglées par tag + digest (mettre à jour les deux ensemble).
# ============================================================

# ---- Étape 1 : dépendances Composer (Composer n'est pas dans l'image finale) ----
FROM composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# ---- Étape 2 : image finale ----
FROM php:8.2-apache@sha256:a5ca3797b19e8ba69905233f79fae6db191d2477f8e6d5194f2c05f01e22e104

LABEL org.opencontainers.image.title="birostweb" \
      org.opencontainers.image.description="Site vitrine Birostweb - Theo Birost, developpeur web full-stack" \
      org.opencontainers.image.url="https://birostweb.fr"

# Modules Apache nécessaires (réécriture, en-têtes de sécurité, cache)
RUN a2enmod rewrite headers expires

# Config PHP de production + limites de payload (formulaire de contact : < 32 Ko)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'variables_order = EGPCS\npost_max_size = 32K\nupload_max_filesize = 1K\nmax_file_uploads = 0\n' > "$PHP_INI_DIR/conf.d/zz-app.ini"

# Apache : port via ${APP_PORT} (validé par l'entrypoint), limite de corps, pas de bannière,
# configuration entièrement préparée au build (aucune écriture dans /etc à l'exécution).
RUN printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\nLimitRequestBody 32768\n' > /etc/apache2/conf-available/zz-app.conf \
    && a2enconf zz-app \
    && sed -i 's/^Listen 80$/Listen ${APP_PORT}/; s/^\s*Listen 443$/Listen 8443/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${APP_PORT}>/' /etc/apache2/sites-available/000-default.conf \
    && sed -i '/<\/VirtualHost>/i \
    <Directory /var/www/html>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Dépendances PHP + code (propriété root, lecture seule pour l'utilisateur d'exécution)
COPY --from=vendor /app/vendor ./vendor
COPY site/ ./
RUN chown -R root:root /var/www/html \
    && find /var/www/html -type d -exec chmod 0555 {} + \
    && find /var/www/html -type f -exec chmod 0444 {} +

# Répertoires d'exécution Apache accessibles à www-data (pid, verrous, logs)
RUN mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

COPY --chmod=0555 entrypoint.sh /usr/local/bin/entrypoint.sh

ENV APP_PORT=8080
USER www-data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD ["/bin/sh","-c","curl -fsS http://127.0.0.1:${PORT:-8080}/robots.txt >/dev/null || exit 1"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
