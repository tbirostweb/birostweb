# ============================================================
#  birostweb.fr — image de production (PHP + Apache)
#  Le formulaire de contact envoie par SMTP (PHPMailer), donc on a besoin
#  d'un back-end PHP. HTTPS/TLS est géré en amont par Traefik (Dokploy).
#  Apache tourne SANS root (www-data) ; le code est en lecture seule pour lui.
# ============================================================
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# PHP >= 8.3.36 : correctif php2shell (CVE-2026-109618 / CVE-2026-109619)
FROM php:8.3.36-apache

LABEL org.opencontainers.image.title="birostweb" \
      org.opencontainers.image.description="Site vitrine Birostweb - Theo Birost, developpeur web full-stack" \
      org.opencontainers.image.url="https://birostweb.fr"

# Modules Apache nécessaires (réécriture, en-têtes de sécurité, cache)
RUN a2enmod rewrite headers expires

# Config PHP de production (display_errors off, expose_php off, etc.)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "variables_order = EGPCS" > "$PHP_INI_DIR/conf.d/zz-app.ini"

# GD : ré-encodage des images uploadées en admin (métadonnées supprimées, redimensionnement)
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
        libpng-dev libjpeg-dev libwebp-dev curl \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd \
    && rm -rf /var/lib/apt/lists/*

# Apache non-root : port via variable d'environnement (aucune modification de config au démarrage),
# journaux vers stdout/stderr, dossiers d'exécution accessibles à www-data.
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && sed -i 's/Listen 80/Listen ${LISTEN_PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${LISTEN_PORT}>/' /etc/apache2/sites-available/*.conf \
    && sed -i 's#^ErrorLog .*#ErrorLog /proc/self/fd/2#' /etc/apache2/apache2.conf \
    && sed -i 's#CustomLog .*#CustomLog /proc/self/fd/1 combined#' /etc/apache2/sites-available/000-default.conf \
    && sed -i '/<\/VirtualHost>/i \
    <Directory /var/www/html>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>' /etc/apache2/sites-available/000-default.conf \
    && mkdir -p /var/run/apache2 /var/lock/apache2 \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

WORKDIR /var/www/html

# Dépendances PHP (PHPMailer, phpdotenv) construites à part
COPY --from=vendor /app/vendor ./vendor

# Code du site (tout le contenu de site/ devient la racine web), propriété root :
# le processus web ne peut pas modifier le code (ni .htaccess).
COPY site/ ./
RUN chown -R root:root /var/www/html && find /var/www/html -type d -exec chmod 755 {} + \
    && find /var/www/html -type f -exec chmod 644 {} +

COPY entrypoint.sh /usr/local/bin/
RUN chmod 755 /usr/local/bin/entrypoint.sh

USER www-data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD ["/bin/sh","-c","curl -fsS http://127.0.0.1:${PORT:-8080}/ >/dev/null || exit 1"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
