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

# File d'attente : indispensable pour que les e-mails Brevo ne ralentissent pas
# les requêtes. La table `jobs` existe déjà (migration 0001_01_01_000002).
QUEUE_CONNECTION=database

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
events), `queue:restart`, puis `reload php-fpm` (purge OPcache). Équivalent manuel :

```bash
php artisan migrate --force
php artisan optimize        # config:cache + event:cache + route:cache + view:cache
php artisan queue:restart
sudo systemctl reload php8.4-fpm
```

> ⚠️ Après un `config:cache`, toute modification du `.env` exige de relancer
> `php artisan config:cache` (ou `php artisan optimize:clear`) pour être prise en compte.

### Worker de file d'attente (obligatoire si `QUEUE_CONNECTION=database`)

Un processus doit consommer la file en continu. Exemple **systemd**
(`/etc/systemd/system/pas-queue.service`) :

```ini
[Unit]
Description=PAS ANBG queue worker
After=network.target

[Service]
User=www-data
Restart=always
WorkingDirectory=/var/www/pas
ExecStart=/usr/bin/php artisan queue:work --queue=notifications,default --sleep=1 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now pas-queue.service
```

À défaut de worker, l'application reste fonctionnelle : l'envoi des e-mails est
**déjà différé après la réponse HTTP** (cf. §2), donc la requête utilisateur n'est
jamais bloquée même sans file d'attente.

---

## 2. Optimisations code déjà appliquées (local + serveur)

| Date | Changement | Effet |
|------|-----------|-------|
| 2026-06-10 | `BrevoMailService::dispatch()` diffère l'envoi e-mail **après la réponse HTTP** (`dispatch()->afterResponse()`) en contexte web. Synchrone conservé en console/tests. | La requête ne bloque plus sur l'API/SMTP Brevo (jusqu'à plusieurs secondes). |
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
