# Analyse globale — Application ANBG Pilotage PAS / PAO / PTA

_Date : 2026 • Version auditée : branche `local/laravel-13-ai-pta`_

---

## 1. Vue d'ensemble

| Aspect | Valeur |
|---|---|
| Framework back | **Laravel 13.17.0** (PHP 8.3+, local PHP 8.4.19) |
| Auth | **Laravel Sanctum 4.x-dev** (session web + tokens API, verrou compatible Laravel 13) |
| Base de données | SQLite (dev) / **PostgreSQL** (prod) — `pdo_pgsql` requis |
| Moteur de vues | **Blade** (Laravel natif, aucune SPA) |
| Front | **Tailwind CSS 4** (build Vite 7) + Chart.js 4 + D3 + GSAP |
| Real-time | **Laravel Echo + Pusher** (messagerie, notifications) |
| Queue / Jobs | DB queue (`database`) — `GenerateReportJob`, `SendAlertDigestJob` |
| Mail | SMTP (digest d'alertes via `AlertDigestMail`) |
| IA | `laravel/ai` 0.8.1, config `AI_DEFAULT_PROVIDER`, conversations IA persistables, module IA PTA contrôlé par validation humaine, base documentaire/corrections humaines indexables |
| Exports | `barryvdh/laravel-dompdf` `dev-master` compatible Laravel 13 + `maatwebsite/excel` 3.1.69 + `phpoffice/phpword` 1.4.0 + exports PDF/Word/Excel IA + fichier import PTA mono-feuille `IMPORT_GLOBAL` |
| Tests | PHPUnit 12 — **461 tests passants, 3 skipped, 2759 assertions** après base IA d'apprentissage PTA et export `IMPORT_GLOBAL` mono-feuille |
| Code | ~ 60 modèles Eloquent, 40 contrôleurs, 30 services applicatifs, 65 migrations |

---

## 2. Architecture fonctionnelle

### 2.1 Chaîne métier

```
PAS (Plan Stratégique)
  └── Axes PAS
       └── Objectifs stratégiques PAS
            └── PAO (Plan Opérationnel / Direction · Service)
                 └── Objectifs opérationnels PAO
                      └── PTA (Plan de Travail Annuel / Service)
                           └── Actions (exécution + suivi hebdo/mensuel)
                                ├── Justificatifs (upload sécurisé, scan AV)
                                ├── KPI Mesures (délai · performance · qualité · risque)
                                ├── Weeks (suivi périodique)
                                ├── Logs (traçabilité)
                                └── Workflow financement (DAF ↔ DG)
```

### 2.2 Rôles métier

7 rôles applicatifs + 1 rôle technique :
`super_admin` · `admin` · `dg` · `cabinet` · `planification` · `direction` · `service` (affiché « SERVICES ») · `agent`

Périmètres d'accès calculés dynamiquement via `RolePermissionSettings` (flags `scope.global.read/write`, `scope.direction.*`, `scope.service.*`) — modifiables en ligne via `super_admin/role-permissions`.

### 2.3 Système de délégation
- Modèle `Delegation` : un utilisateur peut agir **au nom** d'une direction/service pour une fenêtre temporelle donnée.
- Intégration dans `User::activeDelegations()` avec cache per-request pour éviter le N+1.
- Policy aware : les `*Policy.php` consomment `DelegationService`.

### 2.4 Modules Blade (workspace)
- `dashboard` (rôle-aware) · `pas` · `pao` · `pta` · `actions` (list + suivi) · `kpi` / `kpi-mesures`
- `alertes` · `reporting` (analytics cross-plan) · `justificatifs` · `messaging`
- `ai_imports` (import IA PTA : upload, analyse, correction, validation, import) · `ai_reports` (rapports IA PAS/PAO/PTA : génération, validation, exports)
- `monitoring` · `audit` · `notifications` · `profile` · `global-search`
- `super_admin` : **18 panneaux** (roles, modules, workflow, calculation, dashboard_profiles, notifications, documents, maintenance, snapshots, simulation, audit_diagnostic, organization, referentials, kpis, appearance, action_policies, settings, templates_export)

---

## 3. Back-end — forces & observations

### 3.1 Points forts

- **Séparation nette Controllers vs Services** : la logique métier est concentrée dans `app/Services/` (≥ 30 services nommés par domaine : `Analytics/`, `Actions/`, `Alerting/`, `Exports/`, `Governance/`, `Messaging/`, `Notifications/`, `Security/`).
- **Policies exhaustives** : `ActionPolicy`, `PasPolicy`, `PaoPolicy`, `PaoAxePolicy`, `PaoObjectifOperationnelPolicy`, `PaoObjectifStrategiquePolicy` — toutes enregistrées dans `AppServiceProvider`.
- **Observers** : `ActionObserver` (recalcul KPI à chaque update/save) et `PlanningCacheObserver` (invalidation cache agrégé sur Pao/Pta/User) → pattern CQRS léger.
- **Audit** : `JournalAudit` + trait `RecordsAuditTrail` → toutes les mutations API sont journalisées.
- **IA sous contrôle humain** : le module PTA stocke les lots analysés, normalise les lignes, expose les erreurs, impose validation/correction avant import et historise les actions IA dans `ai_import_audits`.
- **Apprentissage IA applicatif** : les fichiers officiels `IMPORT_GLOBAL` et référentiel `code_agent` alimentent une base documentaire IA (`ai_knowledge_*`) ; les corrections humaines et rapports validés alimentent `ai_training_examples`.
- **Configuration dynamique runtime** : `PlatformSettings`, `AppearanceSettings`, `WorkflowSettings`, `WorkspaceModuleSettings`, etc., persistés en DB (`platform_settings`) avec snapshots (`platform_setting_snapshots`) pour rollback.
- **Rate limiting login** : 5/10 min par email + 25/10 min par IP (`AppServiceProvider::configureRateLimiting`).
- **Sécurité fichiers** : `Security/Antivirus` + `SecureJustificatifStorage` + `SecureMessageAttachmentStorage` (scan avant stockage, quarantaine).
- **Password policy** : `PasswordHistory` (empêche la réutilisation), `EnsurePasswordIsFresh` middleware (rotation forcée).
- **Exercices budgétaires** : `ExerciceContext` rend tout le domaine temporellement scopé (pas de fuite cross-year).

### 3.2 Points d'attention

| # | Observation | Impact | Recommandation |
|---|---|---|---|
| B1 | `DashboardController.php` ≈ **1800 lignes** | Maintenance difficile, tests lents | Scinder en 6 classes `*DashboardBuilder` (agent/service/direction/planification/dg/cabinet) + `DashboardDataAggregator` |
| B2 | Cache dashboard à **5 min** pour TOUS les rôles sans clé tenant | Risque de fuite cross-user si la clé n'embarque pas `user_id` | Vérifier la clé cache ; préférer `Cache::tags(['dashboard', "user:{$user->id}"])` |
| B3 | Aucune pagination sur les endpoints `reporting/overview` | Risque OOM en prod si volumes d'actions > 5000 | Ajouter `per_page` + `cursor-paginate` |
| B4 | Pas d'index composite documenté sur `actions(exercice_id, pta_id, statut)` | Requêtes dashboard lentes en volume | Vérifier migrations — ajouter au besoin |
| B5 | `Kpi`/`KpiMesure` — pas de contrainte unique `(action_id, period_code)` visible | Doublons possibles | Ajouter une contrainte UNIQUE en migration |
| B6 | Middleware `EnsureActiveAccount` + `EnsurePasswordIsFresh` cumulés sur toutes les routes authentifiées | OK fonctionnellement, mais quelques routes de profil devraient rester accessibles sans rotation pour la changer | Vérifier bypass sur `workspace.profile.edit` |
| B7 | Pas de `HealthCheck` HTTP externe visible (seulement `php artisan anbg:health-check` CLI) | Pas d'endpoint `/up` exposable au load balancer | Exposer `/health` JSON (DB, queue, storage, mail) |
| B8 | Les routes `web.php` présentaient des artefacts de troncature (`adNaNauditDiagnosticIndex`) lors de la lecture précédente | Lecture partielle, pas de corruption réelle du fichier | — |

---

## 4. Front-end — analyse CSS / UI approfondie

### 4.1 Stack déclarée

- **Tailwind CSS 4** via `@tailwindcss/vite` + plugin Laravel Vite
- 2 entrées Vite : `resources/css/app.css` + `resources/js/app.js` (profil **authentifié**) et `resources/css/guest.css` + `resources/js/guest.js` (profil **login**)
- 5 fichiers CSS source (total **52 Ko** non compilés) :
  - `app.css` (12.5 Ko) — entrée principale, `@theme` + composants ANBG
  - `guest.css` (13.3 Ko) — split-panel login avec sphères
  - `_starline.css` (15 Ko) — décors animés (non référencé depuis app.css, à vérifier)
  - `design-light.css` (6.4 Ko) — surcharges thème clair (heavy `!important`)
  - `curved-sidebar.css` (107 octets) — quasi-vide, doublon de `public/curved-sidebar.css`
  - `lamp-login-gsap.css` (5 Ko) — effet lampe GSAP (doublon de `public/lamp-login-gsap.css`)
- 14 fichiers JS source, tous importés dans `app.js`

### 4.2 Problèmes CSS identifiés

#### ❌ Incohérence typographique
- `tailwind.config.js` déclare `font-family: Inter`
- `app.css` `@theme` déclare `Manrope, Public Sans`
- `chart-theme.js` applique `Manrope, Public Sans`
- `guest.css` `@theme` déclare `Manrope, Source Serif 4`
- `vite-assets.blade.php` charge via Google Fonts : `Public Sans`, `Source Serif 4`, `Manrope`, `Poppins`
- `tailwind.config.js` n'est probablement **pas utilisé** (Tailwind v4 utilise `@theme` dans le CSS, pas `tailwind.config.js`) → **fichier mort ou trompeur**.

#### ❌ Double système de thèmes
- `@theme` (Tailwind v4) expose `--color-anbg-*`
- `app.css` déclare en parallèle `:root { --anbg-navy, --anbg-blue... }`
- `vite-assets.blade.php` injecte en plus `$appearanceSettings->cssVariablesInline()` → **3ème source de variables**
- `design-light.css` surcharge encore ces variables en mode clair

➜ **Redondance critique** : les mêmes couleurs sont définies à 3 endroits. Une modification d'un token doit être synchronisée manuellement.

#### ❌ Guerre de spécificité
`design-light.css` utilise `!important` intensivement pour surcharger `app.css`. Symptôme classique d'une architecture CSS sans cascade contrôlée.

#### ❌ Mojibake dans les labels du sidebar
`resources/views/components/admin/sidebar.blade.php` contient `DÃ©lÃ©gations`, `RÃ©tention` → un fichier **UTF-8 a été réinterprété en Latin-1** lors d'un commit. À nettoyer (recoder `iconv -f CP1252 -t UTF-8`).

#### ❌ Nomenclature CSS incohérente
Au moins **5 préfixes** coexistent sans règle :
- `.eas-*` (ex: `eas-sidebar`)
- `.dealdeck-*` (ex: `dealdeck-sidebar-panel`)
- `.showcase-*` (ex: `showcase-hero`, `showcase-panel`, `showcase-kpi-card`)
- `.admin-*`
- `.anbg-*`
- `.login-*`
- Classes utilitaires Tailwind inline

➜ Aucune méthodologie déclarée (BEM / ITCSS / CUBE). Fait suspecter l'importation de plusieurs templates commerciaux agrégés.

#### ❌ CSS orphelin / doublons fichiers
- `resources/css/curved-sidebar.css` (107 octets) vs `public/curved-sidebar.css` (servi en static)
- `resources/css/lamp-login-gsap.css` vs `public/lamp-login-gsap.css`
- `resources/css/_starline.css` — **pas de `@source` qui y fait référence** → probablement non compilé.

#### ❌ Code inline dans les layouts
- `resources/views/layouts/admin.blade.php` : ~50 lignes `<style>` + **~600 lignes `<script>`** (toggle thème, horloge, notifs, dropdowns, dialogues, audio).
  - Impossible à tester / tree-shaker / cacher en fichiers revisionnés.
  - Contournement CSP si une politique stricte `script-src 'self'` est ajoutée.

#### ⚠️ Polices Google chargées à chaque requête
`vite-assets.blade.php` charge **4 familles × plusieurs graisses** depuis `fonts.googleapis.com`. Ajouter un fallback auto-hébergé pour :
- Performance (TTI)
- RGPD (Google Fonts = transfert UE → US)

#### ⚠️ Accessibilité
- `aria-*` présents, `focus-visible` stylé : bon point
- Pas de **skip-link** visible dans `admin.blade.php`
- Contraste à vérifier sur `text-white/45` (titres de groupes sidebar) sur fond sombre ANBG
- `.login-sphere-*` avec `pointer-events: none` : bien
- Aucune gestion `prefers-reduced-motion` sur les animations GSAP

### 4.3 JavaScript

- Point d'entrée propre (`app.js` → imports ES)
- `bootstrap.js` devrait configurer Axios + Echo → à vérifier
- `dashboard-render.js` et `dashboard-charts-init.js` chargent Chart.js mais pas en **code splitting** → bundle monolithique
- `window.getAnbgChartTheme` / `window.applyAnbgChartDefaults` exposés globalement : utile pour Blade inline, mais à encapsuler dans un namespace `window.ANBG`

---

## 5. Sécurité

### 5.1 Bonnes pratiques constatées
- CSRF géré par Laravel (Blade `@csrf` + middleware web)
- Sanctum pour l'API, sans cookie SameSite=None
- Rate limiting login ciblé (email + IP)
- Antivirus avant stockage de justificatifs et de pièces jointes messagerie
- Historique de mots de passe (`PasswordHistory`)
- Rotation mot de passe (`EnsurePasswordIsFresh`)
- `SafeSql` helper (app/Support) pour construire des clauses dynamiques contrôlées
- Audit journal (`JournalAudit`) sur les mutations critiques
- Soft-throttle sur la connexion (configurable via `security.php`)

### 5.2 Points à vérifier/renforcer
| # | Item | Sévérité |
|---|---|---|
| S1 | En-têtes HTTP sécurité (CSP, HSTS, Referrer-Policy, X-Frame-Options) — pas de middleware `SecurityHeaders` visible | **Haut** |
| S2 | Upload validation : MIME réelle (pas seulement extension) — présumée OK via `Antivirus` mais à tester explicitement | Moyen |
| S3 | Session config : cookie `secure`, `http_only`, `same_site=lax` — à confirmer dans `config/session.php` | Haut |
| S4 | `APP_DEBUG=false` en prod : rappelé dans README | OK |
| S5 | Sanctum : TTL des tokens, stratégie de révocation (endpoint de logout API ?) | Moyen |
| S6 | Les polices Google externes peuvent déclencher des blocages CSP strictes | Bas |

---

## 6. Tests

### 6.1 Couverture actuelle
- **Feature** (~40) : workflow financement, sécurité workflow action, digest alertes, planning API, seeders, sécurité hardening, toutes les facettes `super_admin` (18 panneaux couverts), login session, profile web, messaging, référentiel, workspace web, notifications, health check, production-safe seeder, CRUD org, photo profil, dashboard profiles.
- **Unit** (~11) : `ActionPolicy`, `PaoPolicy`, `PasPolicy`, `UserModel`, `DelegationService`, `ActionTrackingService`, `ReportingAnalyticsService`, `MessagingDirectoryService`, `SafeSql`, `UiLabel`.

### 6.2 Manques identifiés
| Zone | Manque |
|---|---|
| `DashboardController` | Aucun test Feature dédié aux 6 variants de rôle |
| Exports (xlsx/pdf) | Aucun test du contenu généré |
| Antivirus / SecureStorage | Pas de test unitaire des services `Security/` |
| KPI calculation | Pas de test du calcul `kpi_global = f(delai, performance, qualite, risque)` selon `ActionCalculationSettings` |
| API — pas de test contractuel (schema / Postman run) | Postman collection fournie mais non jouée en CI |

---

## 7. Dette technique & risques

### 7.1 Dette identifiée

1. **Front** : 3 systèmes de variables CSS à unifier, 5 préfixes de classes, ~650 lignes inline dans `admin.blade.php`, mojibake sidebar.
2. **Back** : `DashboardController` monolithique (1800 lignes), cache dashboard fragile à la clé, pas de pagination sur `reporting/overview`.
3. **Docs** : `tailwind.config.js` n'est plus lu par Tailwind 4 mais reste présent (trompe le dev).
4. **Runtime** : pas d'endpoint `/health` HTTP pour LB/K8s.
5. **Sécurité** : pas de middleware de security headers (CSP/HSTS).
6. **CI** : aucun pipeline GitHub Actions / GitLab CI visible à la racine.
7. **Fichiers résiduels** : `diff.txt`, `tmp_ppt_extract_*/` (déjà supprimés et gitignorés).

### 7.2 Risques

| Risque | Probabilité | Impact |
|---|---|---|
| Fuite de données cross-user via cache mal clé | Moyenne | **Critique** |
| Régression UI lors d'un changement de token couleur (sync 3 sources) | Haute | Moyen |
| OOM sur `reporting/overview` en volume prod | Moyenne | Haut |
| Absence d'en-têtes CSP facilite XSS via contenu utilisateur (messages, justificatifs HTML) | Moyenne | Haut |
| Google Fonts bloquées par réseau client (ANBG derrière proxy) | Moyenne | Bas (fallback) |

---

## 8. Recommandations prioritaires

### 🔴 Priorité 1 — sécurité & fiabilité
1. Ajouter middleware `SecurityHeaders` (CSP nonce-based, HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy).
2. Auditer la clé de cache de `DashboardController::index` (inclure `user_id`, `role`, `scope`, `exercice_id`).
3. Exposer `/health` (DB + queue + storage + mail) consommable par LB.
4. Vérifier `config/session.php` : `secure=true`, `http_only=true`, `same_site=lax`.
5. Ajouter tests sécurité : upload MIME forgé, CSRF absent, tokens Sanctum révoqués.

### 🟠 Priorité 2 — front / design system
6. **Unifier** les tokens couleur : une seule source de vérité = `@theme` dans `app.css`, alimenté au build par `PlatformSettings`.
7. **Supprimer** `tailwind.config.js` (inactif en v4) ou le transformer en ES module documenté.
8. **Migrer** les ~50 lignes `<style>` et ~600 lignes `<script>` de `admin.blade.php` vers `resources/css/admin-shell.css` et `resources/js/admin-shell.js`.
9. **Corriger** l'encodage de `sidebar.blade.php` (mojibake `DÃ©lÃ©gations` → `Délégations`).
10. **Éliminer** `design-light.css` en refactorant en `@media (prefers-color-scheme: light)` + classes `.light:*` Tailwind.
11. **Supprimer** `_starline.css` si non utilisé, ou l'importer explicitement.
12. **Unifier** la nomenclature : garder `.anbg-*` + Tailwind, dépréciér `.eas-*`, `.dealdeck-*`, `.showcase-*` dans un fichier `legacy.css`.
13. **Auto-héberger** les polices (Manrope + Public Sans) via `npm i @fontsource/*`.
14. Ajouter `prefers-reduced-motion` sur animations GSAP (login).

### 🟡 Priorité 3 — maintenabilité
15. Scinder `DashboardController` en 6 builders + 1 aggregator.
16. Ajouter pagination cursor sur endpoints reporting lourds.
17. Rendre `DashboardData` sérialisable et testable unitairement.
18. Mettre en place un pipeline CI (phpunit + pint + npm run build).
19. Générer un storybook Blade minimal pour les composants `components/ui/`.
20. Documenter la nomenclature CSS dans `docs/design-system.md`.

---

## 9. Synthèse exécutive

✅ **L'application est globalement saine**, bien structurée (Services/Policies/Observers), avec une couverture de tests correcte et des pratiques de sécurité solides (Antivirus, PasswordHistory, Rate limiting, Audit).

⚠️ **Le front est le maillon faible** : empilement de 5 systèmes CSS, 3 sources de variables, code inline massif dans `admin.blade.php`, mojibake, fichier `tailwind.config.js` inactif — c'est la zone la plus urgente à rationaliser.

⚠️ **Le back a 2 dettes structurelles** : `DashboardController` monolithique (1800 lignes) et absence de middleware de security headers HTTP.

🎯 **Chantier recommandé prioritaire** : **"Design System v2"** = unification des tokens + extraction du shell admin + suppression de `design-light.css` + correction encodage sidebar. ROI très élevé (6–10 j-h) pour une réduction massive de dette.
