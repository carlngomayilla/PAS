# Specifications Techniques
## Application ANBG PAS / PAO / PTA / Actions

- Version: 1.1
- Date: 2026-03-09

## 1. Stack Technique
Backend:

- PHP 8.2
- Laravel Framework 12
- Laravel Sanctum (API token)
- DomPDF (export PDF)

Frontend:

- Blade
- Tailwind CSS v4
- JS natif + modules Vite

Base de donnees:

- SQL relationnel (schema Laravel migrations)

## 2. Architecture Applicative
Architecture logique:

- `Controllers Web`: orchestration ecrans
- `Controllers API`: endpoints REST
- `Services metier`: calculs, workflow, notifications
- `Models Eloquent`: persistance et relations
- `Requests`: validation des entrees
- `Policies/Traits`: controle scope role/direction/service

Composants centraux:

- `ActionTrackingService`: suivi periodique, statuts, KPI, alertes
- `WorkspaceNotificationService`: notifications modulees
- `MonitoringWebController`: pilotage/reporting/alertes
- `DashboardController`: synthese role-aware

## 3. Organisation Des Modules
### 3.1 Planification

- PAS
- PAO
- PTA
- axes/objectifs PAS
- axes/objectifs strat/op PAO

### 3.2 Execution

- actions
- periodes de suivi (`action_weeks`)
- validation hierarchique de cloture
- justificatifs polymorphes

### 3.3 Gouvernance

- referentiels (directions/services/utilisateurs)
- notifications
- audit
- monitoring/reporting

## 4. Gestion Des Acces
Modele RBAC:

- roles en base `users.role`
- scope en base `users.direction_id`, `users.service_id`

Mecanisme:

- middleware `auth`
- checks en controleur
- trait `AuthorizesPlanningScope`

Principes:

- global read/write: admin, dg, planification
- global read only: cabinet
- direction scope: direction
- service scope: service
- assignment scope: agent (actions assignees)

## 5. Regles Techniques Workflow
PAS/PAO/PTA:

- statuts: `brouillon`, `soumis`, `valide`, `verrouille`
- transitions controlees en controller
- retour brouillon motive et audite

Action:

- statut legacy + `statut_dynamique`
- statut validation:
  - `non_soumise`
  - `soumise_chef`
  - `rejetee_chef`
  - `validee_chef`
  - `rejetee_direction`
  - `validee_direction`

## 6. Gestion Des Donnees
Contraintes fortes:

- cles etrangeres direction/service coherentes
- unicite sur les periodes de suivi action
- indexation sur statuts, echeances, scope

Integrite metier:

- action rattachee a un PTA
- responsable action doit etre un `agent`
- gel ecriture selon statut validation

## 7. Notifications
Stockage:

- table `notifications` Laravel (JSON `data`)

Payload typique:

- `title`, `message`
- `module`
- `entity_type`, `entity_id`
- `url`, `status`, `priority`

Distribution:

- recipients determines par role + scope direction/service
- exclusion optionnelle de l auteur de l action

## 8. Audit Et Tracabilite
Audit structure:

- table `journal_audit`
- ancienne/nouvelle valeur JSON
- module, action, entite cible
- utilisateur, date, IP, user-agent

Logs execution action:

- table `action_logs`
- niveau (`info`, `warning`, `critical`)
- type evenement et details JSON

## 9. Reporting Et Exports
Reporting:

- consolidation role-aware
- filtres par direction/service
- indicateurs volumes, statuts, alertes, details

Exports:

- CSV (stream)
- PDF (DomPDF)

## 10. Performance
Points d optimisation:

- pagination sur listings
- index SQL pour filtres critiques
- prechargement Eloquent `with()`
- requetes scopees pour minimiser le volume

## 11. Securite
Mesures actuelles:

- authentification obligatoire
- autorisation en profondeur sur chaque action metier
- validation stricte via FormRequests
- stockage local des justificatifs hors acces public direct
- telechargement controle via route autorisee

Mesures recommandees court terme:

- rate limit sur login et endpoints sensibles
- scans antivirus sur upload
- chiffrement eventuel de certains justificatifs sensibles

## 12. Exploitation Et Deploiement
Prerequis:

- PHP 8.2+, Composer, DB SQL
- Node/NPM (build assets Vite/Tailwind)

Commandes standard:

- `composer install`
- `php artisan migrate`
- `php artisan db:seed` (si jeu de test requis)
- `npm install`
- `npm run dev` ou `npm run build`
- `php artisan serve`

## 13. Notes D Architecture

- Le schema contient des tables `kpis` / `kpi_mesures` utilisees pour calculs et reporting.
- Le module `school_erp` existe en base mais n est pas dans le flux metier principal PAS/PAO/PTA/Actions.
- Les notifications et l audit sont des briques transverses obligatoires du pilotage.
