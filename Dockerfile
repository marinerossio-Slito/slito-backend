# syntax=docker/dockerfile:1

# Image de base FrankenPHP (Caddy + PHP) — fournit "frankenphp" et le script
# "install-php-extensions". Le composer.lock contient des paquets (doctrine/orm,
# doctrine-bundle, stimulus-bundle...) qui exigent PHP >= 8.4 : on utilise donc
# PHP 8.4 (le projet exige ">= 8.2", donc 8.4 convient aussi).
FROM dunglas/frankenphp:1-php8.4

# Extensions PHP nécessaires :
# - @composer : installe le binaire Composer (absent de l'image de base)
# - pdo_pgsql : connexion PostgreSQL (Doctrine)
# - intl      : validation / formats (utilisé par Symfony Validator)
# - opcache, apcu : performance en production
# - zip       : dépendances Composer
RUN install-php-extensions \
    @composer \
    pdo_pgsql \
    intl \
    opcache \
    apcu \
    zip

WORKDIR /app

ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1

# Code de l'application.
# Le .dockerignore exclut vendor/, var/, les clés JWT locales, .env.local, etc.
COPY . .

# Dépendances PHP de production uniquement.
# --no-scripts : on n'exécute pas les commandes Symfony ici (cache:clear, etc.)
# car elles ont besoin des variables d'environnement réelles (APP_SECRET,
# DATABASE_URL...), fournies seulement au démarrage du conteneur par Render.
# Voir docker/entrypoint.sh pour la suite de l'initialisation.
RUN composer install --no-dev --no-scripts --no-progress --optimize-autoloader \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p var/cache var/log config/jwt

# Configuration du serveur web (Caddy / FrankenPHP)
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

# Script de démarrage : attente DB, clé JWT, migrations, cache
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
