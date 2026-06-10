#!/usr/bin/env bash
#
# Déploiement production PAS ANBG (Ubuntu Server).
# Enchaîne dépendances, build des assets, migrations et mise en cache des
# optimisations (config, routes, vues, events) + redémarrage du worker de file.
#
# Usage :
#   bash scripts/deploy.sh            # déploiement complet
#   SKIP_GIT=1 bash scripts/deploy.sh # sans git pull (déjà à jour)
#   SKIP_NPM=1 bash scripts/deploy.sh # sans rebuild des assets front
#
# Cible : Ubuntu Server (php-fpm + systemd).
# Pré-requis serveur : php 8.3+, composer, node/npm, et QUEUE_CONNECTION=database
# avec un worker systemd (cf. docs/optimisation-performance.md).
#
# Variables optionnelles :
#   FPM_SERVICE=php8.4-fpm  service php-fpm à recharger (vide l'OPcache). Mettre
#                           FPM_SERVICE="" pour désactiver le rechargement.

set -euo pipefail

# Racine du projet (dossier parent de scripts/).
cd "$(dirname "$0")/.."

echo "==> Déploiement PAS ANBG — $(date '+%Y-%m-%d %H:%M:%S')"

# Garde-fou : ne jamais déployer avec APP_DEBUG=true (perf + sécurité).
if grep -qE '^APP_DEBUG\s*=\s*true' .env 2>/dev/null; then
    echo "!! ATTENTION : APP_DEBUG=true dans .env. Passez à false avant de déployer en prod." >&2
    exit 1
fi

# Mise en maintenance ; on garantit la sortie de maintenance même en cas d'erreur.
php artisan down --render="errors::503" --retry=15 || true
trap 'php artisan up || true' EXIT

if [ "${SKIP_GIT:-0}" != "1" ]; then
    echo "==> git pull"
    git pull --ff-only
fi

echo "==> composer install (prod)"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

if [ "${SKIP_NPM:-0}" != "1" ]; then
    echo "==> build des assets front"
    npm ci
    npm run build
fi

echo "==> migrations"
php artisan migrate --force

echo "==> lien de stockage public (idempotent)"
php artisan storage:link 2>/dev/null || true

echo "==> mise en cache des optimisations"
php artisan optimize:clear
php artisan optimize          # config:cache + event:cache + route:cache + view:cache

echo "==> redémarrage du worker de file d'attente"
php artisan queue:restart

# Rechargement de php-fpm : vide l'OPcache pour que le nouveau code et le
# config:cache soient pris en compte immédiatement. Sans effet si php-fpm n'est
# pas géré par systemd ou si sudo non disponible (étape non bloquante).
FPM_SERVICE="${FPM_SERVICE-php8.4-fpm}"
if [ -n "$FPM_SERVICE" ] && command -v systemctl >/dev/null 2>&1; then
    echo "==> rechargement de $FPM_SERVICE (purge OPcache)"
    sudo -n systemctl reload "$FPM_SERVICE" 2>/dev/null \
        || systemctl reload "$FPM_SERVICE" 2>/dev/null \
        || echo "   (rechargement php-fpm ignoré : privilèges insuffisants — à faire manuellement)"
fi

# Sortie de maintenance explicite (le trap reste un filet de sécurité).
php artisan up
trap - EXIT

echo "==> Déploiement terminé avec succès."
