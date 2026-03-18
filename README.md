# ANBG - Suivi PAS / PAO / PTA (Laravel)

Application Laravel de pilotage strategique et operationnel:

- `PAS` (Plan d Actions Strategique)
- `PAO` (Plan d Actions Operationnel par direction)
- `PTA` (Plan de Travail Annuel par service)
- `Actions`, `Activites`, `KPI`, `Mesures KPI`, `Audit`

## 1. Prerequis

- PHP 8.2+
- Composer
- Node.js 20+ et npm (pour compiler Tailwind/Vite)

## 2. Installation

```bash
composer install
npm install
php artisan key:generate
php artisan migrate:fresh --seed
npm run build
```

## 3. Lancer l application

Commande recommandee:

```bash
php -S 127.0.0.1:8000 -t public server.php
```

En mode developpement front (Tailwind/Vite):

```bash
npm run dev
```

Note: si Vite n est pas disponible localement, l application bascule automatiquement sur Tailwind CDN pour conserver le rendu visuel.

## 3.b Connexion cinema (GSAP lampe ON/OFF)

L ecran de connexion utilise une animation GSAP (lampe + corde + glow).

- Vue: `resources/views/auth/lamp-login.blade.php`
- CSS: `resources/css/lamp-login-gsap.css`
- JS: `resources/js/lamp-login-gsap.js`
- Son click (a fournir): `public/sfx/click.mp3`

Puis ouvrir:

- Page de connexion web: `http://127.0.0.1:8000/login`
- Espace web modules: `http://127.0.0.1:8000/workspace`
- API: `http://127.0.0.1:8000/api`

## 4. Comptes de test (seeders)

Mot de passe commun: `Pass@12345`

- `admin@anbg.test` (admin)
- `dg@anbg.test` (direction generale)
- `planification@anbg.test` (planification)
- `cabinet@anbg.test` (cabinet, lecture)
- `daf.direction@anbg.test` (directeur DAF)
- `dsi.direction@anbg.test` (directeur DSI)
- `dpp.direction@anbg.test` (directeur DPP)
- `finance.service@anbg.test` (service DAF)
- `dev.service@anbg.test` (service DSI)
- `planif.service@anbg.test` (service DPP)

## 5. Endpoints API principaux

Authentification:

- `POST /api/login`
- `GET /api/me`
- `POST /api/logout`

Referentiel:

- `GET /api/referentiel/directions`
- `GET /api/referentiel/services`
- `GET /api/referentiel/utilisateurs`

Planification:

- `apiResource /api/pas`
- `apiResource /api/pas-axes`
- `apiResource /api/pas-objectifs`
- `apiResource /api/paos`
- `apiResource /api/pao-axes`
- `apiResource /api/pao-objectifs-strategiques`
- `apiResource /api/pao-objectifs-operationnels`
- `apiResource /api/ptas`
- `apiResource /api/actions`
- `apiResource /api/activites`
- `apiResource /api/kpis`
- `apiResource /api/kpi-mesures`
- `apiResource /api/justificatifs`
- `GET /api/justificatifs/{id}/download`
- `GET /api/journal-audit`
- `GET /api/reporting/overview`
- `GET /api/alertes`

Pages web metier:

- `GET /workspace`
- `GET /workspace/pas` (+ creation, modification, suppression)
- `GET /workspace/pas-axes` (+ creation, modification, suppression)
- `GET /workspace/pas-objectifs` (+ creation, modification, suppression)
- `GET /workspace/pao` (+ creation, modification, suppression)
- `GET /workspace/pao-axes` (+ creation, modification, suppression)
- `GET /workspace/pao-objectifs-strategiques` (+ creation, modification, suppression)
- `GET /workspace/pao-objectifs-operationnels` (+ creation, modification, suppression)
- `GET /workspace/pta` (+ creation, modification, suppression)
- `GET /workspace/actions` (+ creation, modification, suppression)
- `GET /workspace/activites` (+ creation, modification, suppression)
- `GET /workspace/kpi` (+ creation, modification, suppression)
- `GET /workspace/kpi-mesures` (+ creation, modification, suppression)
- `GET /workspace/justificatifs` (+ creation, modification, suppression, telechargement)
- `GET /workspace/referentiel/directions` (+ creation, modification, suppression)
- `GET /workspace/referentiel/services` (+ creation, modification, suppression)
- `GET /workspace/referentiel/utilisateurs` (+ creation, modification, suppression)
- `GET /workspace/pilotage`
- `GET /workspace/reporting`
- `GET /workspace/reporting/export/excel`
- `GET /workspace/reporting/export/pdf`
- `GET /workspace/alertes`
- `GET /workspace/audit` (profils globaux)

Workflow validation web (boutons sur listes PAS/PAO/PTA):

- `Soumettre` (brouillon -> soumis)
- `Valider` (soumis -> valide)
- `Verrouiller` (valide -> verrouille)
- `Retour brouillon` (soumis/valide -> brouillon) avec `motif_retour` obligatoire
- Timeline visuelle des transitions (incluant motif de retour) disponible sur les formulaires d edition PAS/PAO/PTA.

Notifications automatiques:

- Commande: `php artisan alertes:notifier`
- Mode simulation: `php artisan alertes:notifier --dry-run`
- Planification quotidienne: `07:30` (voir `php artisan schedule:list`)
- Cible: profils operationnels (admin, DG, planification, direction, service) avec alertes sur leur perimetre.

## 6. Verification rapide

```bash
php artisan route:list --path=api
php artisan schedule:list
php artisan test
```
