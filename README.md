# ANBG - Pilotage PAS / PAO / PTA

Application Laravel de pilotage strategique et operationnel de l'ANBG.

## Perimetre fonctionnel

L'application couvre la chaine suivante :

- `PAS` : planification strategique via wizard `PAS -> Axes -> Objectifs strategiques`
- `PAO` : objectifs operationnels par `direction` et `service`, rattaches a un objectif strategique
- `PTA` : plan de travail annuel du service, rattache a un PAO
- `Actions` : execution, affectation, suivi, justificatifs, commentaires et cloture
- `Alertes` : centre d'alertes avec navigation directe vers la cause
- `Pilotage` et `Reporting` : consolidation transversale, exports `.xlsx` / `.pdf` et visualisations
- `Referentiels` : directions, services, utilisateurs et delegations
- `Profil`, `Notifications`, `Audit`, `Retention`, `Documentation API`

Le module `SchoolErp` n'est plus dans le runtime applicatif.
Les anciens ecrans `pas-axes` et `pas-objectifs` ne sont plus des parcours metier actifs : les routes de compatibilite redirigent vers le wizard PAS.

## Organisation et roles seedes

Le seed ANBG injecte le referentiel organisationnel suivant :

- directions : `DG`, `DGA`, `SCIQ`, `UCAS`, `DS`, `DSIC`, `DAF`
- services : `19` services rattaches a ces directions
- utilisateurs : comptes ANBG reels avec emails `@anbg.ga`

Roles metier exposes dans l'application :

- `Administrateur`
- `DG`
- `CABINET`
- `PLANIFICATION`
- `DIRECTION`
- `SERVICES`
- `AGENT`

Note technique :

- le role utilisateur affiche `SERVICES`
- la valeur interne conservee pour l'autorisation reste `service`
- le role `admin` reste disponible pour l'administration technique globale

## Prerequis

- PHP 8.2+
- Composer
- Node.js 20+
- extension PHP `pdo_pgsql` si la base cible est PostgreSQL
- SQLite pour une demo locale rapide, ou PostgreSQL pour un deploiement reel

## Installation locale

```bash
composer install
npm install
php artisan key:generate
php artisan migrate --seed
npm run build
```

Pour repartir d'une base propre avec le jeu de demonstration ANBG complet :

```bash
php artisan migrate:fresh --seed --force
```

## Demarrage

```bash
php artisan serve
```

Application web : `http://127.0.0.1:8000`

En developpement front :

```bash
npm run dev
```

## Comptes de demonstration

Mot de passe commun : `Pass@12345`

Comptes utiles pour la recette rapide :

- `admin@anbg.ga` : administrateur technique global
- `ingrid@anbg.ga` : DG
- `loick.adan@anbg.ga` : CABINET
- `hilaire.nguebet@anbg.ga` : PLANIFICATION
- `suzy.mbazogo@anbg.ga` : DIRECTION
- `marie.simba@anbg.ga` : SERVICES
- `claude.azizet@anbg.ga` : AGENT

Exemples de matricules disponibles apres seed :

- `ADM-001`
- `DG-002`
- `CAB-003`
- `PLA-016`
- `DIR-019`
- `SRV-026`
- `AGT-030`

Des placeholders restent volontairement presents dans le seed pour les postes non nommes :

- `dga@anbg.ga`
- `directeur.ds@anbg.ga`
- `directeur.dsic@anbg.ga`
- `directeur.daf@anbg.ga`

## Entrees web principales

- `/dashboard`
- `/workspace`
- `/workspace/pas`
- `/workspace/pao`
- `/workspace/pta`
- `/workspace/actions`
- `/workspace/actions/{action}/suivi`
- `/workspace/pilotage`
- `/workspace/reporting`
- `/workspace/alertes`
- `/workspace/referentiel/directions`
- `/workspace/referentiel/services`
- `/workspace/referentiel/utilisateurs`
- `/workspace/referentiel/delegations`
- `/workspace/profil`
- `/workspace/audit`
- `/workspace/retention`
- `/workspace/documentation-api`

## Entrees API principales

- `POST /api/login`
- `GET /api/me`
- `POST /api/logout`
- `apiResource /api/pas`
- `apiResource /api/paos`
- `apiResource /api/ptas`
- `apiResource /api/actions`
- `GET /api/reporting/overview`
- `GET /api/alertes`
- `GET /api/journal-audit`
- `GET /workspace/documentation-api/openapi.yaml`

## Workflow metier

1. `Cabinet / Planification` cree le PAS et structure le wizard `PAS -> Axes -> Objectifs strategiques`.
2. Chaque `Direction` ouvre ses `PAO` par objectif strategique et par service.
3. Le `Chef de service` cree le `PTA` du service rattache au PAO.
4. Le `Chef de service` ouvre les `Actions` et les affecte a un agent.
5. L'`Agent` saisit ses periodes, justificatifs et observations.
6. Le `Chef de service` puis la `Direction` valident la cloture de l'action.

## Commandes utiles

```bash
php artisan route:list
php artisan schedule:list
php artisan test
php artisan view:cache
```

Regeneration du jeu de demonstration planning sans repartir d'une base vide :

```bash
php artisan db:seed --class=RefreshPlanningDemoSeeder --force
```

Reseed du referentiel ANBG seul :

```bash
php artisan db:seed --class=AnbgOrganizationSeeder --force
```

## Etat de la qualite

Le dernier etat valide localement couvre :

- `60` tests passants
- build front `npm run build` OK
- vues Blade compilees avec `php artisan view:cache`

Les derniers lots ont notamment couvre :

- dashboard et vues analytiques
- roles et perimetres PAS / PAO / PTA / Actions
- suivi et cloture des actions
- alertes et reporting
- exports `.xlsx` / `.pdf`
- profil, navigation workspace et theming

## Deploiement

Avant un deploiement reel, prevoir au minimum :

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL` correct
- vraie base de donnees serveur
- SMTP reel
- worker de queue
- scheduler systeme
- stockage persistant pour `storage/`
- sauvegardes et supervision

### Variables d'environnement minimales

Un template pret pour la production PostgreSQL est fourni dans :

```bash
.env.production.example
```

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votre-domaine

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pas_anbg
DB_USERNAME=postgres
DB_PASSWORD=...
DB_SSLMODE=prefer

QUEUE_CONNECTION=database
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@anbg.ga
MAIL_FROM_NAME="ANBG Pilotage"
```

### Sequence de mise en production

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
php artisan optimize
php artisan view:cache
```

Verification rapide de la connexion PostgreSQL :

```bash
php artisan migrate:status
```

### Taches d'exploitation a brancher

Worker de queue :

```bash
php artisan queue:work --queue=default,notifications
```

Scheduler systeme :

```bash
php artisan schedule:run
```

### Verifications post-deploiement

1. connexion avec `admin@anbg.ga`
2. acces a `/dashboard` et `/workspace`
3. test d'export `Reporting` en `.xlsx` et `.pdf`
4. test de televersement d'un justificatif
5. verification des notifications et des alertes
6. verification du worker et du scheduler dans les logs

### Reseed local uniquement

Les commandes de seed ci-dessous sont prevues pour la demo locale ou les environnements de recette, pas pour une production deja exploitee :

```bash
php artisan migrate:fresh --seed --force
php artisan db:seed --class=RefreshPlanningDemoSeeder --force
php artisan db:seed --class=AnbgOrganizationSeeder --force
```
