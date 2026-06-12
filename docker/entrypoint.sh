#!/bin/sh
# Initialisation au démarrage du conteneur (Render fournit les vraies
# variables d'environnement à ce stade : DATABASE_URL, APP_SECRET, etc.)
set -e

echo "==> Attente de la base de données..."
i=1
while [ "$i" -le 30 ]; do
    if php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; then
        echo "==> Base de données disponible."
        break
    fi
    echo "    (tentative $i/30...)"
    sleep 2
    i=$((i + 1))
done

echo "==> Clé JWT (génération si absente)..."
if [ ! -f config/jwt/private.pem ] || [ ! -f config/jwt/public.pem ]; then
    php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
fi

echo "==> Migrations Doctrine..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "==> Assets..."
php bin/console assets:install public --env=prod --no-interaction || true
php bin/console importmap:install --no-interaction || true

echo "==> Préchauffage du cache..."
php bin/console cache:clear --env=prod --no-debug || true

echo "==> Démarrage du serveur..."
exec "$@"
