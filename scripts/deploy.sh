#!/usr/bin/env bash
#
# Déploiement production PAS ANBG (Ubuntu Server).
# Enchaîne dépendances, build des assets, migrations et mise en cache des
# optimisations (config, routes, vues, events) + rechargement du runtime de file.
#
# Usage :
#   bash scripts/deploy.sh            # déploiement complet
#   SKIP_GIT=1 bash scripts/deploy.sh # sans git pull (déjà à jour)
#   SKIP_NPM=1 bash scripts/deploy.sh # sans rebuild des assets front
#
# Cible : Ubuntu Server (php-fpm + systemd).
# Pré-requis serveur : php 8.4+, composer, node/npm, et soit un worker systemd
# (file database), soit Redis + Horizon supervise par un moniteur de processus.
#
# Variables optionnelles :
#   FPM_SERVICE=php8.4-fpm  service php-fpm à recharger (vide l'OPcache). Mettre
#                           FPM_SERVICE="" pour désactiver le rechargement.

set -euo pipefail

# NEXT_PILOT_SERVICE peut cibler une unite systemd differente lorsque le
# dashboard Next est active. Valeur par defaut : anbg-dashboard-pilot.service.

# Racine du projet (dossier parent de scripts/).
cd "$(dirname "$0")/.."

NEXT_PILOT_SERVICE="${NEXT_PILOT_SERVICE:-anbg-dashboard-pilot.service}"
# Ce chemin canonique doit rester identique à EnvironmentFile dans l'unité
# systemd. Il n'est volontairement pas surchargeable : le préflight doit
# toujours valider le fichier réellement consommé par le runtime Next.
NEXT_PILOT_ENV_FILE="/etc/anbg-pas/dashboard-pilot.env"

case "$NEXT_PILOT_SERVICE" in
    ''|*[!A-Za-z0-9@_.-]*|*.service.service)
        echo "!! NEXT_PILOT_SERVICE doit etre un nom d unite systemd .service valide." >&2
        exit 1
        ;;
    *.service) ;;
    *) NEXT_PILOT_SERVICE="${NEXT_PILOT_SERVICE}.service" ;;
esac

case "$NEXT_PILOT_ENV_FILE" in
    /*) ;;
    *)
        echo "!! NEXT_PILOT_ENV_FILE doit etre un chemin absolu." >&2
        exit 1
        ;;
esac

validate_next_pilot_runtime() {
    if ! command -v systemctl >/dev/null 2>&1; then
        echo "!! systemctl est requis lorsque le dashboard Next est active." >&2
        return 1
    fi

    if [ ! -f "$NEXT_PILOT_ENV_FILE" ] || [ ! -r "$NEXT_PILOT_ENV_FILE" ]; then
        echo "!! Le fichier runtime Next $NEXT_PILOT_ENV_FILE doit exister et etre lisible." >&2
        return 1
    fi

    NEXT_PILOT_INTERNAL_URL_COUNT="$(grep -cE '^LARAVEL_INTERNAL_URL=' "$NEXT_PILOT_ENV_FILE" || true)"
    NEXT_PILOT_INTERNAL_URL="$(sed -n 's/^LARAVEL_INTERNAL_URL=//p' "$NEXT_PILOT_ENV_FILE")"
    if [ "$NEXT_PILOT_INTERNAL_URL_COUNT" != "1" ] \
        || ! php -r '
            try {
                $parts = parse_url($argv[1] ?? "");
            } catch (Throwable) {
                exit(1);
            }

            if (! is_array($parts)) {
                exit(1);
            }

            $host = strtolower(trim((string) ($parts["host"] ?? ""), "[]"));
            $isIpv4Loopback = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && str_starts_with($host, "127.");
            $port = $parts["port"] ?? null;
            $isValid = in_array((string) ($parts["scheme"] ?? ""), ["http", "https"], true)
                && ($host === "localhost" || $host === "::1" || $isIpv4Loopback)
                && ! isset($parts["user"])
                && ! isset($parts["pass"])
                && ! isset($parts["query"])
                && ! isset($parts["fragment"])
                && in_array((string) ($parts["path"] ?? ""), ["", "/"], true)
                && ($port === null || ($port >= 1 && $port <= 65535));

            exit($isValid ? 0 : 1);
        ' "$NEXT_PILOT_INTERNAL_URL"; then
        echo "!! LARAVEL_INTERNAL_URL doit etre defini une seule fois avec une origine HTTP(S) loopback et un port entre 1 et 65535." >&2
        return 1
    fi
}

restart_next_pilot_runtime() {
    echo "==> activation et redemarrage du runtime Next ($NEXT_PILOT_SERVICE)"

    if [ "$(id -u)" = "0" ] \
        && systemctl enable "$NEXT_PILOT_SERVICE" 2>/dev/null \
        && systemctl restart "$NEXT_PILOT_SERVICE" 2>/dev/null; then
        NEXT_PILOT_SYSTEMCTL_SCOPE="system-root"
    elif sudo -n systemctl enable "$NEXT_PILOT_SERVICE" 2>/dev/null \
        && sudo -n systemctl restart "$NEXT_PILOT_SERVICE" 2>/dev/null; then
        NEXT_PILOT_SYSTEMCTL_SCOPE="system-sudo"
    elif systemctl --user enable "$NEXT_PILOT_SERVICE" 2>/dev/null \
        && systemctl --user restart "$NEXT_PILOT_SERVICE" 2>/dev/null; then
        NEXT_PILOT_SYSTEMCTL_SCOPE="user"
    else
        echo "!! Impossible d activer et redemarrer $NEXT_PILOT_SERVICE." >&2
        return 1
    fi

    case "$NEXT_PILOT_SYSTEMCTL_SCOPE" in
        system-root)
            systemctl is-enabled --quiet "$NEXT_PILOT_SERVICE" \
                && systemctl is-active --quiet "$NEXT_PILOT_SERVICE"
            ;;
        system-sudo)
            sudo -n systemctl is-enabled --quiet "$NEXT_PILOT_SERVICE" \
                && sudo -n systemctl is-active --quiet "$NEXT_PILOT_SERVICE"
            ;;
        user)
            systemctl --user is-enabled --quiet "$NEXT_PILOT_SERVICE" \
                && systemctl --user is-active --quiet "$NEXT_PILOT_SERVICE"
            ;;
    esac || {
        echo "!! $NEXT_PILOT_SERVICE doit etre enabled et actif apres activation." >&2
        return 1
    }
}

stop_next_pilot_runtime() {
    if ! command -v systemctl >/dev/null 2>&1; then
        echo "   Runtime Next inactif : systemctl indisponible."
        return 0
    fi

    if systemctl is-active --quiet "$NEXT_PILOT_SERVICE" 2>/dev/null \
        || systemctl is-enabled --quiet "$NEXT_PILOT_SERVICE" 2>/dev/null; then
        echo "==> desactivation du runtime Next ($NEXT_PILOT_SERVICE)"

        if ! { { [ "$(id -u)" = "0" ] && systemctl disable --now "$NEXT_PILOT_SERVICE" 2>/dev/null; } \
            || sudo -n systemctl disable --now "$NEXT_PILOT_SERVICE" 2>/dev/null; }; then
            echo "!! Impossible de desactiver le service systeme $NEXT_PILOT_SERVICE." >&2
            return 1
        fi
    fi

    if systemctl --user is-active --quiet "$NEXT_PILOT_SERVICE" 2>/dev/null \
        || systemctl --user is-enabled --quiet "$NEXT_PILOT_SERVICE" 2>/dev/null; then
        echo "==> desactivation du runtime Next utilisateur ($NEXT_PILOT_SERVICE)"
        systemctl --user disable --now "$NEXT_PILOT_SERVICE" 2>/dev/null || {
            echo "!! Impossible de desactiver le service utilisateur $NEXT_PILOT_SERVICE." >&2
            return 1
        }
    fi

    if systemctl is-active --quiet "$NEXT_PILOT_SERVICE" 2>/dev/null \
        || systemctl is-enabled --quiet "$NEXT_PILOT_SERVICE" 2>/dev/null \
        || systemctl --user is-active --quiet "$NEXT_PILOT_SERVICE" 2>/dev/null \
        || systemctl --user is-enabled --quiet "$NEXT_PILOT_SERVICE" 2>/dev/null; then
        echo "!! $NEXT_PILOT_SERVICE est toujours actif ou enabled malgre le kill-switch." >&2
        return 1
    fi
}

echo "==> Déploiement PAS ANBG — $(date '+%Y-%m-%d %H:%M:%S')"

# Garde-fou : ne jamais déployer avec APP_DEBUG=true (perf + sécurité).
if grep -qE '^APP_DEBUG\s*=\s*true' .env 2>/dev/null; then
    echo "!! ATTENTION : APP_DEBUG=true dans .env. Passez à false avant de déployer en prod." >&2
    exit 1
fi

# Le drapeau voulu doit etre relu depuis l environnement, pas depuis un ancien
# config:cache : cela garantit que la desactivation reste un vrai kill-switch.
php artisan config:clear

# Preflight avant maintenance : une activation doit disposer de son
# environnement runtime. Le controle est repete apres config:cache.
NEXT_PILOT_PREFLIGHT_ENABLED="$(php artisan config:show dashboard.next_pilot.enabled --no-ansi | awk 'NF { value = $NF } END { print value }')"
if [ "$NEXT_PILOT_PREFLIGHT_ENABLED" = "true" ] || [ "$NEXT_PILOT_PREFLIGHT_ENABLED" = "1" ]; then
    validate_next_pilot_runtime
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
    npm run next:build
fi

echo "==> migrations"
php artisan migrate --force

echo "==> lien de stockage public (idempotent)"
php artisan storage:link 2>/dev/null || true

echo "==> mise en cache des optimisations"
php artisan optimize:clear
php artisan optimize          # config:cache + event:cache + route:cache + view:cache

NEXT_PILOT_ENABLED="$(php artisan config:show dashboard.next_pilot.enabled --no-ansi | awk 'NF { value = $NF } END { print value }')"
if [ "$NEXT_PILOT_ENABLED" = "true" ] || [ "$NEXT_PILOT_ENABLED" = "1" ]; then
    validate_next_pilot_runtime
    restart_next_pilot_runtime
else
    stop_next_pilot_runtime
fi

echo "==> rechargement du runtime de file d'attente"
QUEUE_DRIVER="$(php artisan config:show queue.default --no-ansi | awk 'NF { value = $NF } END { print value }')"

if [ "$QUEUE_DRIVER" = "redis" ]; then
    HORIZON_ACTIVE=0

    set +e
    HORIZON_STATUS_OUTPUT="$(php artisan horizon:status 2>&1)"
    set -e

    case "$HORIZON_STATUS_OUTPUT" in
        *"Horizon is running."*|*"Horizon is paused."*) HORIZON_ACTIVE=1 ;;
    esac

    if [ "$HORIZON_ACTIVE" = "1" ]; then
        echo "   Horizon actif : terminaison gracieuse (le moniteur le relancera)"
        php artisan horizon:terminate
    else
        echo "   Horizon inactif : attente du démarrage par le moniteur de processus"
    fi

    HORIZON_RESTART_TIMEOUT="${HORIZON_RESTART_TIMEOUT:-60}"

    case "$HORIZON_RESTART_TIMEOUT" in
        ''|*[!0-9]*)
            echo "!! HORIZON_RESTART_TIMEOUT doit etre un entier entre 1 et 300 secondes." >&2
            exit 1
            ;;
    esac

    if [ "$HORIZON_RESTART_TIMEOUT" -lt 1 ] || [ "$HORIZON_RESTART_TIMEOUT" -gt 300 ]; then
        echo "!! HORIZON_RESTART_TIMEOUT doit etre compris entre 1 et 300 secondes." >&2
        exit 1
    fi

    HORIZON_RUNNING=0
    HORIZON_WAITED=0

    while [ "$HORIZON_WAITED" -lt "$HORIZON_RESTART_TIMEOUT" ]; do
        sleep 1
        HORIZON_WAITED=$((HORIZON_WAITED + 1))

        set +e
        HORIZON_STATUS_OUTPUT="$(php artisan horizon:status 2>&1)"
        HORIZON_STATUS_CODE=$?
        set -e

        if [ "$HORIZON_STATUS_CODE" = "0" ]; then
            case "$HORIZON_STATUS_OUTPUT" in
                *"Horizon is running."*)
                    HORIZON_RUNNING=1
                    break
                    ;;
            esac
        fi
    done

    if [ "$HORIZON_RUNNING" != "1" ]; then
        echo "!! Horizon n est pas running apres ${HORIZON_RESTART_TIMEOUT}s." >&2
        exit 1
    fi

    echo "   Horizon running apres ${HORIZON_WAITED}s"
else
    echo "   File $QUEUE_DRIVER : signal de redemarrage des workers Laravel"
    php artisan queue:restart
fi

echo "==> redémarrage du serveur temps réel Reverb"
php artisan reverb:restart

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

echo "==> contrôle de santé applicatif"
php artisan anbg:health-check

# Sortie de maintenance explicite (le trap reste un filet de sécurité).
php artisan up
trap - EXIT

echo "==> Déploiement terminé avec succès."
