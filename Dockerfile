# ============================================================
#  birostweb.fr — image de production (PHP + Apache)
#  Le formulaire de contact envoie par SMTP (PHPMailer), donc on a besoin
#  d'un back-end PHP. HTTPS/TLS est géré en amont par Traefik (Dokploy).
# ============================================================
FROM php:8.2-apache

LABEL org.opencontainers.image.title="birostweb" \
      org.opencontainers.image.description="Site vitrine Birostweb - Theo Birost, developpeur web full-stack" \
      org.opencontainers.image.url="https://birostweb.fr"

# Modules Apache nécessaires (réécriture, en-têtes de sécurité, cache)
RUN a2enmod rewrite headers expires

# Config PHP de production (display_errors off, expose_php off, etc.)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "variables_order = EGPCS" > "$PHP_INI_DIR/conf.d/zz-app.ini"

# ServerName pour éviter les warnings
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Extensions PHP + unzip pour Composer
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
        libzip-dev unzip curl \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:lts /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dépendances PHP (PHPMailer, phpdotenv)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Code du site (tout le contenu de site/ devient la racine web)
COPY site/ ./

# Autoriser les .htaccess sur la racine web
RUN sed -i '/<\/VirtualHost>/i \
    <Directory /var/www/html>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>' /etc/apache2/sites-available/000-default.conf

# Permissions
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# Port applicatif (repris par l'entrypoint via $PORT, défaut 8080)
COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD ["/bin/sh","-c","curl -fsS http://127.0.0.1:${PORT:-8080}/ >/dev/null || exit 1"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
