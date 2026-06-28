# Audit Phase 0 — Conformité avec la spec v2 PAS ANBG

**Projet :** e-Pilotage PAS ANBG (Laravel 13.17.0, PHP 8.3+/8.4 local, PostgreSQL/SQLite)
**Date de l'audit :** 2026-05-28
**Base auditée :** `C:\Users\chris\OK\PAS` (branche courante)
**Mise à jour technique :** 2026-06-28 — migration locale Laravel 13 sur `local/laravel-13-ai-pta`.
**Référence :** Spec v2 consolidée du 28/05/2026 (Module Import Excel, paramétrage 3 modes, suppression KPI conformité, KPI Délai gradué, workflow report d'échéance Chef → SCIQ → DG)

---

## 0. Synthèse exécutive

Le projet est **très avancé** : la majorité des briques métier de la spec v2 sont **déjà implémentées ou ébauchées** (table `planning_imports`, `deadline_extension_requests`, `PlanningExcelImportService`, `DeadlineExtensionRequestService`, `WorkspaceNotificationService` + `BrevoMailService`, audit `journal_audit`, chiffrement justificatifs, modes `quantitatif/sans_quantite/sous_actions` côté `Action`, statuts `a_parametrer/parametre`).

Les écarts à la spec v2 se concentrent sur **trois familles** :

1. **Suppression à effectuer** : KPI conformité (`action_kpis.kpi_conformite`, `actions.taux_conformite`), note du chef (`evaluation_note`, `evaluation_commentaire`, `taux_valide_chef`, `conformite_chef`, `observation_qualite_chef`, et leurs équivalents `direction_*`).
2. **Renforcement à apporter** : KPI Délai gradué (actuellement binaire dans `calculateActionKpis`), format ISO 8601 strict des dates Excel, dry-run d'import, exécution asynchrone si > 500 lignes, ajout des colonnes Excel optionnelles `cible_quantitative` + `unite_mesure`.
3. **Décisions à arbitrer** : nommage des rôles (`direction` vs `directeur`, `service` vs `chef-service`), retrait du mode `MODE_MIXTE` non prévu par la spec, devenir du système `PlanningUnlockRequest` qu'il remplace par la nouvelle mécanique DG → application automatique.

Aucun module existant n'est à reconstruire. Il s'agit principalement de **migrations de suppression**, d'ajustements de service (`ActionTrackingService::calculateActionKpis`, `PlanningExcelImportService::parseDate`) et de l'ajout de quelques garde-fous (validation ISO, file asynchrone, RMO principal).

---

## 1. Inventaire de l'existant

### 1.1 Stack

- Laravel 13.17.0, PHP 8.3+ (PHP 8.4.19 en local), Sanctum `4.x-dev`, DomPDF wrapper `dev-master` pour les exports.
- `laravel/ai` 0.8.1 installe la configuration IA, les stubs agents/tools et les tables de conversations IA.
- `maatwebsite/excel` 3.1.69, `phpoffice/phpspreadsheet` 1.30.5 et `phpoffice/phpword` 1.4.0 sont disponibles pour les futurs imports/exports IA PTA.
- **Aucune dépendance Spatie Permission** : la matrice de permissions est custom et stockée dans la table `platform_settings` (clés `role_permissions_<role>`).

### 1.2 Modèles métier (`app/Models`)

| Modèle | Présent | Rôle dans la spec v2 |
|---|---|---|
| `Pas`, `PasAxe`, `PasObjectif` | oui | PAS, axes, objectifs stratégiques |
| `Pao`, `PaoAxe`, `PaoObjectifStrategique`, `PaoObjectifOperationnel` | oui | Plan opérationnel direction |
| `ObjectifOperationnel` | oui | Table dédiée (séparée des objets PAO) |
| `Pta` | oui | Plan de travail annuel par service |
| `Action`, `SousAction` | oui | Cœur du suivi |
| `ActionKpi`, `ActionLog`, `ActionWeek` | oui | (Note : `action_weeks` supprimée en 2026‑05‑22) |
| `Justificatif` (morphé) | oui | Pièces attachées à actions/sous-actions |
| `DeadlineExtensionRequest` | oui | Workflow report d'échéance |
| `PlanningImport` | oui | Trace d'un import Excel |
| `PlanningUnlockRequest` | oui | **Ancien mécanisme de déverrouillage** des dates — à dépréciser |
| `JournalAudit` | oui | Audit applicatif |
| `Direction`, `Service`, `UniteDg`, `Exercice` | oui | Référentiels |
| `User`, `Delegation`, `UserAssignmentHistory`, `PasswordHistory` | oui | Gouvernance utilisateurs |

### 1.3 Services métier (`app/Services`)

```
Actions/
  ActionBusinessRules.php
  ActionIndicatorService.php
  ActionProgressService.php
  ActionStatusService.php
  ActionTrackingService.php      (66 ko — service central)
  DeadlineExtensionRequestService.php
Alerting/
Analytics/
Dashboard/
Exports/
Governance/
Imports/
  PlanningExcelImportService.php       (35 ko — moteur d'import)
  PlanningImportCodeGenerator.php
  SimpleSpreadsheet.php
Messaging/
Notifications/
  BrevoEmailTemplateFactory.php
  BrevoMailService.php
  WorkspaceNotificationService.php     (74 ko — service central de notifications)
Planning/
  PlanningWorkflowRulesService.php
Scope/
Security/
  AntivirusScanner.php
  PasswordPolicyService.php
  SecureJustificatifStorage.php
  SecureMessageAttachmentStorage.php
```

### 1.4 Contrôleurs

Web : `ActionWebController`, `ActionTrackingWebController`, `PasWebController`, `PaoWebController`, `PtaWebController`, `PlanningImportWebController`, `PlanningUnlockWebController`, `KpiWebController`, `KpiMesureWebController`, `AuditWebController`, `NotificationWebController`, `GovernanceWebController`, `MonitoringWebController`, `MessagingWebController`, `ReferentielWebController`, `SuperAdminWebController`, `DependentSelectController`, `GlobalSearchWebController`, `PersonalTaskWebController`, `ProfileWebController`.
API : `ActionController`, `ActionValidationController`, `ActionCommentController`, `ActionWeekController`, `PasController`, `PaoController`, `PtaController`, `PasAxeController`, `PasObjectifController`, `KpiController`, `KpiMesureController`, `AlerteController`, `AuthController`, `JournalAuditController`, `ReferentielController`, `ReportingController`.

### 1.5 Policies

`ActionPolicy`, `PaoPolicy`, `PasPolicy` + concerns `HandlesPaoAuthorization`.

### 1.6 Statuts et énumérations

**`Action::MODE_*` (déjà présents) :**
- `MODE_SOUS_ACTIONS = 'sous_actions'`
- `MODE_QUANTITATIF = 'quantitatif'`
- `MODE_SANS_QUANTITE = 'sans_quantite'`
- `MODE_MIXTE = 'mixte'` ← **non prévu par la spec v2**

**`actions.statut_parametrage` (CHECK PostgreSQL) :** `'a_parametrer'`, `'parametre'`. Aligné spec v2.

**`actions.statut_validation` (CHECK PostgreSQL) :** `non_soumise`, `soumise_chef`, `rejetee_chef`, `correction_demandee`, `validee_chef`, `rejetee_direction`, `validee_direction`. Les deux derniers (`*_direction`) ne sont pas prévus par la spec v2 qui s'arrête au chef.

**`actions.statut_dynamique` :** colonne `string(30)`, default `non_demarre`. Pas de contrainte CHECK explicite.

**`Pas::STATUS_*` :** `actif`, `cloture`, `archive` (après migration `purge_legacy_planning_workflow_statuses`).
**`Pao::STATUS_*` :** `en_cours`, `valide`, `cloture`, `archive`, `verrouille`.
**`Pta::STATUS_*` :** `en_cours`, `cloture`, `archive`, `valide`, `verrouille`.

**`User::ROLE_*` (inventaire complet) :**

```
ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ADMIN_FONCTIONNEL,
ROLE_DG, ROLE_PLANIFICATION,
ROLE_DIRECTION, ROLE_SERVICE, ROLE_AGENT,
ROLE_CABINET, ROLE_CHEF_UNITE, ROLE_COLLABORATEUR,
ROLE_SCIQ, ROLE_UCAS,
ROLE_SCIQ_SUIVI_GLOBAL, ROLE_CHEF_UNITE_SCIQ, ROLE_CHEF_UNITE_DGA,
ROLE_CHEF_UNITE_CABINET, ROLE_CHEF_UNITE_UCAS,
ROLE_DGA_SUPERVISION, ROLE_CABINET_SUPERVISION,
ROLE_AUDITEUR, ROLE_INVITE_LECTURE
```

La migration `2026_05_21_120000_consolidate_indispensable_roles.php` consolide la plupart de ces rôles vers le set indispensable : `super_admin`, `admin_fonctionnel`, `dg`, `planification`, `direction`, `service`, `agent`, `auditeur`.

### 1.7 Audit et stockage

- **Audit** : table `journal_audit` (modèle custom, pas Spatie auditing) avec `user_id`, `module`, `entite_type`, `entite_id`, `action`, `ancienne_valeur` (JSON), `nouvelle_valeur` (JSON), `adresse_ip`, `user_agent`, `created_at`. Trait `RecordsAuditTrail` côté contrôleurs API.
- **Justificatifs** : table `justificatifs` morphée (`justifiable_type/id`), avec chiffrement optionnel (`est_chiffre`) + hash applicatif. Service `SecureJustificatifStorage` + `AntivirusScanner` (ClamAV).
- **Brevo** : table `brevo_email_log` (event_type, recipient_email, subject, status `queued|sent|failed`, brevo_message_id, error_message, sent_at). Service `BrevoMailService` + `BrevoEmailTemplateFactory`. La règle "échec Brevo non bloquant" est explicitement documentée dans le code.

### 1.8 Conventions du projet

- Tables en snake_case pluriel (`actions`, `paos`, `ptas`, `deadline_extension_requests`).
- Statuts en snake_case minuscule (`a_parametrer`, `non_demarre`, `en_cours`, `cloture`).
- Constantes de statut sur les modèles : `STATUS_*` (et `MODE_*` sur `Action`).
- Migrations utilisent `forceFill()` côté services pour les champs non mass-assignables (workflow, statuts, traces utilisateurs).
- Compatibilité PostgreSQL **et** SQLite (tests), avec MySQL en option. Les contraintes CHECK sont posées via `DB::statement` dans `up()` pour PostgreSQL.

---

## 2. Conflits avec la spec v2 (par sévérité)

### 2.1 Critique — Suppression de KPI conformité et note du chef

**Existant à supprimer :**

| Table | Colonne | Justification suppression |
|---|---|---|
| `action_kpis` | `kpi_conformite` | spec v2 supprime KPI conformité |
| `actions` | `taux_conformite` | idem |
| `actions` | `evaluation_note` | spec v2 supprime la note du chef |
| `actions` | `evaluation_commentaire` | idem |
| `actions` | `taux_valide_chef` | idem |
| `actions` | `conformite_chef` | idem |
| `actions` | `observation_qualite_chef` | idem |
| `actions` | `direction_evaluation_note` | spec v2 arrête la validation au chef |
| `actions` | `direction_evaluation_commentaire` | idem |
| `actions` | `direction_valide_par`, `direction_valide_le` | idem |

**Action : nouvelle migration `drop_chef_quality_note_and_conformite_kpi`** pour retirer ces colonnes proprement, mettre à jour `ActionKpi::$fillable`, `Action::$fillable`, ajuster `ActionTrackingService::calculateActionKpis` qui pose encore `kpi_conformite`. Voir aussi la migration `align_pao_pta_statuses_with_canonical_workflow` (point d'entrée pour la migration des contraintes).

**Impact tests/UI :** vues `resources/views/actions/*` et `resources/views/kpis/*` à dépolluer du KPI conformité et des champs de note chef. Endpoints API qui retournent ces colonnes (`ActionController`, `ActionValidationController`, `KpiController`) à mettre à jour.

### 2.2 Critique — Statuts de validation à plafonner au chef

**Existant :** `actions.statut_validation` autorise `rejetee_direction` et `validee_direction` (CHECK PostgreSQL en place).

**Spec v2 :** workflow arrêté à `validee_chef` / `rejetee_chef`. Les directions ne notent plus ; la validation chef est la décision finale.

**Action :** migration `restrict_action_validation_to_chef` qui repose la contrainte CHECK sur `('non_soumise','soumise_chef','rejetee_chef','correction_demandee','validee_chef')`, drop les colonnes `direction_*`, et migre les enregistrements `validee_direction` → `validee_chef`, `rejetee_direction` → `rejetee_chef` (avec audit explicite).

### 2.3 Majeur — Mode `MIXTE` non prévu par la spec

**Existant :** `Action::MODE_MIXTE = 'mixte'`.

**Spec v2 :** trois modes seulement (Quantitatif / Sans quantité / Sous-actions).

**Action :** identifier les actions utilisant `mode = 'mixte'` (script de migration), proposer leur conversion vers Quantitatif ou Sous-actions selon le cas. Retirer la constante après nettoyage. **Décision à valider avec le métier avant d'exécuter.**

### 2.4 Majeur — KPI Délai actuellement binaire

**Existant :** `ActionTrackingService::calculateActionKpis` calcule un `kpi_delai` qui, selon le code (lignes 909-1000), met en place `0` ou `100` en pratique (cas `STATUS_SUSPENDU`/`STATUS_ANNULE` → 0, sinon binaire à confirmer dans `calculateActionKpis`).

**Spec v2 (recommandation acceptée) :** formule graduée
`KPI_délai = max(0, 100 - (retard_jours / durée_prévue × 100))`.

**Action :** patcher `ActionTrackingService::calculateActionKpis` (ou la méthode `kpiDelai` interne) pour appliquer la formule graduée. Conserver l'ancien comportement derrière un flag de configuration (`config/kpis.php → delai.mode = 'graduated' | 'binary'`) pour permettre un rollback si besoin.

### 2.5 Majeur — Format des dates Excel non strict

**Existant :** `PlanningExcelImportService::parseDate` accepte tout format reconnu par `Carbon::parse` (DD/MM/YYYY, MM/DD/YYYY, ISO, etc.) — ambigu pour les saisies françaises vs anglaises.

**Spec v2 :** ISO 8601 (YYYY-MM-DD) imposé ou cellule de type Date Excel native. Rejet sinon.

**Action :** durcir `parseDate` pour n'accepter que (i) cellule numérique Excel (sérial date) et (ii) chaîne au format `^\d{4}-\d{2}-\d{2}$`. Rejet explicite des autres formats avec message d'erreur ciblé sur la ligne. Mettre à jour le modèle Excel téléchargeable pour pré-formater les colonnes en type Date.

### 2.6 Majeur — Pas de dry-run ni de file asynchrone

**Existant :** `PlanningExcelImportService::execute` exécute la persistance en transaction synchrone après une étape `preview_ready/preview_errors`.

**Spec v2 :** mode dry-run obligatoire (« Tester sans enregistrer ») + Job asynchrone si > 500 lignes valides.

**Actions :**

1. Ajouter un statut `dry_run_ready` et un bouton "Tester sans enregistrer" qui exécute `execute(...)` dans une transaction qui se termine par un `DB::rollBack()` puis stocke le rapport.
2. Créer un `App\Jobs\ExecutePlanningImportJob` dispatché par `PlanningImportWebController` quand `valid_rows > 500`. Le contrôleur web doit renvoyer un statut `queued` et la page de suivi affiche l'état via `planning_imports.status`. Notification à l'utilisateur en fin de traitement.

### 2.7 Majeur — Colonnes Excel `cible_quantitative` et `unite_mesure` absentes

**Existant :** `REQUIRED_COLUMNS` ne contient ni `cible_quantitative` ni `unite_mesure`. Le chef saisit la cible au paramétrage.

**Spec v2 (corrections C.1.3) :** ajouter ces deux colonnes en **optionnel**. Si renseignées, pré-sélection automatique du mode Quantitatif avec valeurs pré-remplies.

**Action :** ajouter `cible_quantitative` (numérique, optionnel) et `unite_mesure` (texte, optionnel) à `REQUIRED_COLUMNS` (ou plus exactement à une nouvelle liste `OPTIONAL_COLUMNS`), adapter `persistRow` pour pré-remplir `actions.quantite_cible` / `actions.unite_cible` et poser `mode_evaluation = 'quantitatif'` quand les deux sont fournis. Mettre à jour le modèle Excel.

### 2.8 Majeur — Limite de fichier à 5 Mo / 5 000 lignes non vérifiée

**Existant :** non identifié dans `PlanningExcelImportService` (ni la taille, ni le nombre de lignes ne sont contrôlés explicitement).

**Spec v2 :** 5 Mo max ou 5 000 lignes max. Au-delà : message d'erreur "Découper par axe".

**Action :** ajouter une validation `validate(['file' => 'file|max:5120|mimes:xlsx,xls'])` dans `PlanningImportWebController` et un comptage de lignes après lecture (`validateSheet`). Renvoyer un message ciblé si dépassement.

### 2.9 Important — Nommage des rôles à arbitrer

**Existant :** `direction`, `service`, `agent`, `planification`, `chef_unite`.
**Spec v2 :** `directeur`, `chef-service`, `chef-unite`, `rmo-agent`, `sciq-planification`.

Trois options :

- **Option A — Renommer** (migration `rename_business_roles`) : changement profond, impacte policies, vues, traductions, tests.
- **Option B — Garder le mapping métier** existant et documenter la correspondance dans `docs/roles.md` (spec v2 est descriptive, pas normative sur les noms techniques). **Recommandation par défaut.**
- **Option C — Adopter Spatie laravel-permission** comme le suggère la spec v2 : trop lourd pour le bénéfice, vu la matrice custom déjà en place dans `platform_settings`.

**Décision à confirmer avec Carl.**

### 2.10 Important — Devenir de `PlanningUnlockRequest`

**Existant :** Modèle + contrôleur (`PlanningUnlockWebController`) pour les demandes de déverrouillage manuel des dates après accord DG.

**Spec v2 :** la nouvelle date approuvée par le DG **s'applique automatiquement** à l'action/sous-action. Plus de déverrouillage manuel.

**Action :** déprécier `PlanningUnlockRequest` (et son contrôleur), s'assurer qu'aucun chemin actif ne l'utilise pour la mise à jour des échéances, conserver les enregistrements historiques en lecture seule. Le nouveau flux passe par `DeadlineExtensionRequest` + `DeadlineExtensionRequestService::applyApprovedDeadline`.

### 2.11 Important — RMO principal pour les actions multi-RMO

**Existant :** table `action_responsables` (migration `2026_04_29_120000`) et relations `responsables()` + `rmos()` sur `Action`. Pas de notion explicite de "RMO principal".

**Spec v2 (C.1.5) :** désigner un RMO principal (premier code par défaut, modifiable par le chef). Seul le RMO principal peut "Soumettre".

**Action :** ajouter une colonne `is_principal` (boolean, default false) sur `action_responsables`. Service `ActionTrackingService::submitClosureForReview` doit vérifier que l'auteur est le RMO principal. Adapter `PlanningExcelImportService::persistRow` pour marquer le **premier code agent** comme principal.

### 2.12 Important — Signalement de difficulté par l'agent

**Existant :** L'agent n'a pas de bouton "Signaler une difficulté de délai". Le flux passe directement par le chef qui crée une `DeadlineExtensionRequest`.

**Spec v2 (C.1.9) :** L'agent peut signaler une difficulté depuis sa page de suivi (notification au chef + pré-remplissage du formulaire de demande officielle).

**Action :** ajouter `App\Models\DeadlineDifficultyReport` (ou réutiliser `ActionLog` avec un type dédié `deadline_difficulty_signaled`), endpoint `POST /workspace/actions/{action}/difficulties`, notification au chef. Quand le chef ouvre une nouvelle `DeadlineExtensionRequest`, pré-remplir les champs depuis le dernier signalement non transformé.

### 2.13 Important — Statuts simplifiés pour `DeadlineExtensionRequest`

**Existant :** colonne `status` `string(60)`, default `soumise`. Pas de contrainte CHECK posée. Le service utilise actuellement (à confirmer) : `soumise`, `en_analyse_sciq`, `transmise_dg`, `approuvee`, `rejetee`, `mise_a_jour_appliquee`.

**Spec v2 (C.1.15) :** 6 statuts simplifiés (idem). Conforme. Aucune action sauf ajout d'une contrainte CHECK pour PostgreSQL.

### 2.14 Mineur — Rappels et escalades à formaliser

**Existant :** `WorkspaceNotificationService` notifie les événements, mais aucun job CRON de rappel n'a été identifié dans `routes/console.php` ou `app/Console/Kernel.php`.

**Spec v2 :** rappel chef à 5 j, SCIQ à 3 j, DG à 5 j, escalade directeur à 10 j (seuils paramétrables).

**Action :** créer une commande artisan `app/Console/Commands/SendWorkflowRemindersCommand.php` planifiée dans `routes/console.php` (`Schedule::command(...)->dailyAt('07:00')`). Stocker les seuils dans `config/notifications.php` (ou `platform_settings` pour modification runtime).

### 2.15 Mineur — Versionning explicite des justificatifs

**Existant :** table `justificatifs` morphée, plusieurs justificatifs par entité possibles. Pas de notion explicite de version.

**Spec v2 (C.2.4) :** conserver toutes les pièces déposées après rejet (versions).

**Action :** ajouter `version` (uint) et `replaced_at` (nullable timestamp) sur `justificatifs` ; lors d'un re-dépôt après rejet, incrémenter `version` et marquer l'ancien `replaced_at = now()`. Vue chef : timeline.

### 2.16 Mineur — Limites taille / formats justificatifs

**Existant :** `SecureJustificatifStorage` scanne via `AntivirusScanner` puis chiffre. Pas de validation explicite de mime/size côté service.

**Spec v2 :** max 10 Mo, formats PDF/JPG/PNG/DOCX/XLSX.

**Action :** ajouter `validate(['file' => 'max:10240|mimes:pdf,jpg,jpeg,png,docx,xlsx'])` dans les FormRequests des contrôleurs `ActionTrackingWebController` et `DeadlineExtensionRequestController`.

### 2.17 Mineur — Modèle Excel téléchargeable et 3 feuilles

**Existant :** non vérifié sur disque (à confirmer dans `resources/templates/` ou `public/templates/`).

**Spec v2 :** modèle .xlsx avec 3 feuilles (IMPORT_GLOBAL avec 3 lignes d'exemple, INSTRUCTIONS, LISTES_VALEURS).

**Action :** générer ce modèle, l'exposer via `PlanningImportWebController::downloadTemplate` (route `/workspace/imports-excel/template`).

### 2.18 Mineur — Indicateurs visuels de retard

**Existant :** pas de service ou de helper Blade unifié identifié pour les badges de délai.

**Spec v2 :** badge vert (>7j), orange (≤7j), rouge (échue), gris (terminée).

**Action :** créer un component Blade `<x-deadline-badge :action="$action" />` et `<x-deadline-badge :sous-action="$sousAction" />`. Le helper s'appuie sur `Action::date_fin` et `Action::date_fin_reelle`.

---

## 3. Points conformes (aucune action requise)

- Architecture **PAS → Axes → OS → PAO → OO → PTA → Actions → Sous-actions** complète.
- Génération automatique des codes via `PlanningImportCodeGenerator` + colonnes `code` uniques sur paos/ptas/objectifs_operationnels/actions.
- Mécanique d'import en plusieurs étapes : upload → mapping → preview → exécution. Modes `MODE_CREATE_ONLY`/`MODE_SKIP_DUPLICATES`/`MODE_UPDATE_EXISTING` équivalents à Création/Complément/Mise à jour.
- Statut `a_parametrer` / `parametre` (contrainte CHECK PostgreSQL en place).
- Soft deletes sur les tables opérationnelles (migration `add_soft_deletes_to_core_operational_tables`).
- Audit trail complet via `journal_audit`.
- Brevo intégré avec log `brevo_email_log` et règle "non bloquant" documentée dans le code.
- Justificatifs chiffrés + antivirus + hash applicatif.
- Workflow `DeadlineExtensionRequest` quasi conforme spec v2 (champs `target_type`, `old/requested/approved_deadline`, `sciq_avis`, `dg_decision`, `applied_by/at`, `is_critical`, `attachment_path`, `metadata` JSON).
- Application automatique de la date approuvée par le DG (`DeadlineExtensionRequestService::applyApprovedDeadline`).
- Mode `quantitatif`, `sans_quantite`, `sous_actions` déjà déclarés dans les constantes `Action::MODE_*`.

---

## 4. Récapitulatif des actions par ordre d'implémentation

| Ordre | Lot | Effort | Bloquant ? |
|---|---|---|---|
| 1 | **Arbitrage rôles** (§2.9) et **mode `MIXTE`** (§2.3) | conversation 30 min | oui, conditionne le reste |
| 2 | Migration `drop_chef_quality_note_and_conformite_kpi` (§2.1) | 1 j (migration + adaptation `ActionTrackingService::calculateActionKpis` + vues KPI + tests) | non |
| 3 | Migration `restrict_action_validation_to_chef` (§2.2) | 0,5 j | non |
| 4 | Patch KPI Délai gradué + flag config (§2.4) | 0,5 j | non |
| 5 | Durcissement `parseDate` ISO 8601 + `OPTIONAL_COLUMNS` + cible/unité (§2.5, §2.7) | 1 j | non |
| 6 | Dry-run + Job asynchrone + limite 5 Mo / 5000 lignes (§2.6, §2.8) | 1 j | non |
| 7 | RMO principal (§2.11) + signalement difficulté agent (§2.12) | 1 j | non |
| 8 | Dépréciation `PlanningUnlockRequest` (§2.10) | 0,5 j | non |
| 9 | Rappels/escalades + commande artisan planifiée (§2.14) | 0,5 j | non |
| 10 | Versionning justificatifs + validation mime/size (§2.15, §2.16) | 0,5 j | non |
| 11 | Modèle Excel 3 feuilles + badges délai (§2.17, §2.18) | 0,5 j | non |
| 12 | Tests E2E des parcours import/paramétrage/suivi/validation/report | 1,5 j | non |
| 13 | Documentation utilisateur PDF par rôle + ERD Mermaid | 1 j | non |

**Effort total estimé : 9 à 10 jours/homme**, hors arbitrages métier (§2.3, §2.9, §2.10) qui peuvent ouvrir des chantiers supplémentaires.

---

## 5. Points d'arbitrage à valider avec Carl avant d'écrire du code

1. **Nommage des rôles** (§2.9) : conserver les rôles techniques actuels (`direction`, `service`, `agent`, `planification`) et documenter la correspondance avec la spec v2, ou renommer ?
2. **Mode `MIXTE`** (§2.3) : combien d'actions en base utilisent ce mode ? Vers quel mode les migrer (Quantitatif ou Sous-actions) ?
3. **Validation direction** (`validee_direction`/`rejetee_direction`) (§2.2) : peut-on dropper sans risquer de perdre des décisions en base ? Lancer un `SELECT COUNT(*) FROM actions WHERE statut_validation IN ('validee_direction','rejetee_direction')` avant la migration.
4. **`PlanningUnlockRequest`** (§2.10) : statistiques d'usage à confirmer. Si encore actif, prévoir un message de transition pour les utilisateurs.
5. **KPI Délai gradué vs binaire** (§2.4) : la spec recommande gradué, mais accepte le binaire en V1 si documenté. Choix à confirmer pour ne pas casser le comparatif historique.
6. **Spatie laravel-permission** (§1.1) : la spec v2 le mentionne en option. La matrice custom via `platform_settings` est-elle conservée ?

---

## 6. Conclusion

Le projet est **en bien meilleure forme que ce que la spec v2 laisse anticiper**. La plupart des briques sont déjà construites avec un niveau de qualité élevé (transactions, lock pessimiste, fail-safe Brevo, chiffrement justificatifs, audit complet). Les écarts identifiés sont **mécaniques et localisés**, pas architecturaux.

L'ordre suggéré (Partie 4) permet de livrer la conformité spec v2 en deux sprints courts sans casser l'existant, à condition d'avoir tranché les six points d'arbitrage (Partie 5) en amont.

---

*Audit produit le 2026-05-28. Référence mémoire projet : `pas_anbg_spec_v2`.*
