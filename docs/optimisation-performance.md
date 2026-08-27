# Optimisation des performances — PAS ANBG

Guide opérationnel pour réduire la latence de l'application (serveur **et** local).
Établi le 2026-06-10 après analyse complète du code.

---

## 1. Réglages serveur (production) — gain majeur

Ces réglages **ne doivent PAS** être appliqués en local (ils gênent le développement).
À reporter **uniquement** dans le `.env` du serveur de production :

```dotenv
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
LOG_STACK=daily
LOG_DAILY_DAYS=14

# File d'attente de production après le drain database décrit plus bas.
# Horizon doit être actif pour consommer les jobs Redis.
QUEUE_CONNECTION=redis

# Filet de sécurité réseau Brevo
BREVO_API_TIMEOUT=5
```

Puis, à **chaque déploiement**, lancer le script fourni (Ubuntu Server) :

```bash
bash scripts/deploy.sh
# Options : SKIP_GIT=1, SKIP_NPM=1, FPM_SERVICE=php8.4-fpm (ou "" pour désactiver)
```

Le script enchaîne : garde-fou `APP_DEBUG`, mode maintenance, `composer install
--no-dev`, build des assets, `migrate --force`, `optimize` (config + routes + vues +
events), rechargement du runtime de queue, puis `reload php-fpm` (purge OPcache).
Avec Redis, il termine Horizon proprement et attend son redémarrage par le
moniteur de processus ; avec une autre connexion, il exécute `queue:restart`.
Socle manuel commun :

```bash
php artisan migrate --force
php artisan optimize        # config:cache + event:cache + route:cache + view:cache
sudo systemctl reload php8.4-fpm
```

> ⚠️ Après un `config:cache`, toute modification du `.env` exige de relancer
> `php artisan config:cache` (ou `php artisan optimize:clear`) pour être prise en compte.

### Worker de file d'attente (alternative `QUEUE_CONNECTION=database`)

Un processus doit consommer la file en continu. Exemple **systemd**
(`/etc/systemd/system/pas-queue.service`) :

```ini
[Unit]
Description=PAS ANBG queue worker
After=network.target

[Service]
User=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/pas
ExecStart=/usr/bin/php artisan queue:work --queue=notifications,exports,ai-imports,default --sleep=1 --tries=3 --timeout=1320 --max-time=3600
TimeoutStopSec=1800

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now pas-queue.service
```

Avec `QUEUE_CONNECTION=redis`, utiliser Horizon sous un moniteur de processus :

```ini
[Unit]
Description=PAS ANBG Horizon
After=network.target

[Service]
User=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/pas
ExecStart=/usr/bin/php artisan horizon
ExecStop=/usr/bin/php artisan horizon:terminate
TimeoutStopSec=1800

[Install]
WantedBy=multi-user.target
```

Après création ou modification de `pas-horizon.service` :

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now pas-horizon.service
```

Horizon utilise `fast_termination=true`. Les superviseurs conservent néanmoins
des timeouts de 660 à 1320 secondes, tous supérieurs aux jobs de cinq minutes,
et inférieurs au `retry_after` Redis de 1500 secondes.

### Bascule manuelle de la file database vers Redis

Ne désactivez jamais l'ancien worker tant que la table `jobs` n'est pas vide.
Le drain explicite est :

```bash
QUEUE_CONNECTION=database php artisan queue:work database --queue=notifications,exports,ai-imports,default --stop-when-empty --timeout=1320 --tries=3
sudo systemctl disable --now pas-queue.service
systemctl is-active pas-queue.service
systemctl is-enabled pas-queue.service
```

Les deux dernières commandes doivent indiquer respectivement `inactive` et
`disabled` avant d'activer Redis/Horizon. Cette vérification reste manuelle :
le script `deploy.sh` ne désactive pas automatiquement ce service historique.

### Build Next sans interruption de la release précédente

Le script actuel lance encore `next:build` directement. En production, arrêter
d'abord `anbg-dashboard-pilot.service`, déplacer
`/var/www/pas/frontend/dashboard-pilot/.next` vers le chemin fixe
`.next.deploy-backup`, puis construire. En cas d'échec ou d'interruption,
remettre ce backup en `.next`, redémarrer le service et exécuter
`php artisan up`. Après un build réussi, redémarrer le service, vérifier
`/dashboard-pilot/health` puis `php artisan anbg:health-check`, et seulement
ensuite supprimer le backup. L'automatisation transactionnelle de cette
procédure nécessite une autorisation opérationnelle explicite avant intégration
dans `deploy.sh`.

Les sessions restent sur `SESSION_DRIVER=database` : les ecrans de liste et de
revocation des sessions ne sont pas encore compatibles avec un stockage Redis.

Sans worker ou Horizon actif, les requêtes peuvent encore placer des jobs dans
la file, mais les e-mails, exports et imports asynchrones ne sont pas exécutés :
le backlog augmente jusqu'au rétablissement d'un consommateur. Un runtime de
queue supervisé est donc obligatoire en production.

---

## 2. Optimisations code déjà appliquées (local + serveur)

| Date | Changement | Effet |
|------|-----------|-------|
| 2026-08-24 | `BrevoMailService::dispatch()` place un job dans la file par destinataire unique, depuis le web comme depuis les commandes et workers. Seuls les tests unitaires livrent synchronement. | Isole la requête et les commandes de la latence API/SMTP Brevo ; nécessite un consommateur de queue actif. |
| 2026-06-10 | `BREVO_API_TIMEOUT` par défaut abaissé `10s → 5s`. | Borne le pire cas réseau. |
| 2026-06-10 | Suppression du module Messagerie. | −2 requêtes SQL par page authentifiée (header). |

---

## 3. Pistes d'optimisation restantes (backlog)

Identifiées lors de l'analyse, non encore traitées (impact / effort) :

1. **Dashboard — invalidation de cache trop large.** Chaque modification d'action
   appelle `bumpDashboard()` ([ActionObserver](../app/Observers/ActionObserver.php)),
   ce qui invalide le cache dashboard de **tous** les utilisateurs (clé indexée sur
   `dashboardVersion()`, [DashboardController::dashboardCacheKey](../app/Http/Controllers/DashboardController.php)).
   Sur une appli active, le cache 5 min saute en permanence.
   → Piste : invalidation ciblée (par direction/service) plutôt que globale.

2. **Dashboard — chargement de toutes les actions.**
   [buildDashboardPagePayload](../app/Http/Controllers/DashboardController.php) fait
   un `->get()` sans pagination avec un arbre d'eager-load profond (sousActions,
   justificatifs, KPI…). Lourd pour les rôles à lecture globale (DG/Admin).
   → Piste : agrégations SQL (`COUNT`/`SUM` groupés) au lieu de charger les modèles.

3. **Layout — `unreadNotifications()->get()`** charge toutes les non-lues à chaque
   page pour le regroupement par module ([admin.blade.php](../resources/views/layouts/admin.blade.php)).
   → Piste : un seul `COUNT(*) GROUP BY data->>'module'` (attention : extraction
   JSON spécifique pgsql vs sqlite des tests).

4. **Bundles JS** : `index.js` (282 kB) + `app.js` (276 kB) + `chart.js` (207 kB).
   → Piste : charger Chart.js en `import()` dynamique uniquement sur les pages à
   graphiques.

5. **Latence réseau DB en prod** : base PostgreSQL distante (`10.30.40.12:5432`).
   → Vérifier que le serveur applicatif et la DB sont sur le même réseau/faible RTT ;
   chaque page dashboard émet de nombreuses requêtes.
