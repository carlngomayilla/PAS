# Journal des modifications — PAS ANBG

Traçabilité chronologique des actions effectuées sur l'application.
Format : entrées datées (les plus récentes en haut), avec description, fichiers modifiés et extraits de code clés.

---

## 2026-05-31 — Fix workflow save→submit sous-action (commentaire toujours optionnel + plus visible)

### Demande utilisateur

> *"DANS LES SOUS ACTION AVEC QUANTITE IL N'Y A PAS LE CHAMP COMMENTAIRE ET DONC L'ACTION
> NE PEUT PAS ETRE ENREGISTRER PUI SOUMISE CAR OUI POUR TOUT TYPE D'ACTION OU SOUS ACTION
> ON ENREGISTRE D'ABORD PUIS ON PEUT LA SOUMETRE PAR LA SUISTE"*

### Bugs identifiés

#### Bug A — Backend bloquait la soumission si commentaire vide

Dans `ActionTrackingWebController::updateSubAction` (ligne ~171) ET
`updateQuantitativeProgress` (ligne ~586), le champ commentaire était marqué
`Rule::requiredIf($isSubmit)` + une vérification explicite renvoyait *"Le commentaire est
obligatoire pour soumettre"*. Or le workflow attendu est : **enregistrer d'abord (save),
puis soumettre plus tard (submit)**, sans contrainte sur le commentaire.

#### Bug B — Champ commentaire visuellement noyé pour les sous-actions quantitatives

Le grid `auto-fit minmax(220px, 1fr)` empilait 5 champs (quantité + résultat + commentaire
+ difficultés + justificatif) → sur viewport moyen le commentaire se retrouvait coincé en
3e position d'une rangée, peu visible.

### Corrections

1. **`app/Http/Controllers/Web/ActionTrackingWebController.php`**
   - `updateSubAction` : `commentaire` passe à `['nullable', 'string', 'max:5000']` (plus de
     `requiredIf($isSubmit)`), retrait du bloc `if($isSubmit && trim(commentaire) === '')`.
   - `updateQuantitativeProgress` : même traitement pour `commentaire_quantitatif`.

2. **`resources/views/workspace/actions/suivi.blade.php`**
   - Formulaire **sous-action** : layout repensé en 2 lignes
     - Ligne 1 (grid compact) : quantité + difficultés + justificatif
     - Ligne 2 (pleine largeur, label en `font-semibold`) : résultat obtenu (si quantité) +
       **commentaire de réalisation** — chaque label porte explicitement `(optionnel)`.
   - Formulaire **action quantitative** : même restructuration, commentaire en pleine
     largeur juste au-dessus des boutons Enregistrer / Soumettre.

### Impact

- ✓ "Enregistrer" et "Soumettre" fonctionnent désormais sans commentaire pour tous les types
  d'action et sous-action.
- ✓ Le champ commentaire est visible en pleine largeur, jamais noyé.
- ✓ Workflow utilisateur respecté : save dès qu'on a une donnée, soumettre quand on est prêt.

### Validation

- Tests `ActionTracking` + `SubAction` : 25 passés (57 assertions) ✓
- Suite Feature complète : 318 passés, 3 skipped, 0 régression (2222 assertions)

---

## 2026-05-31 — Circuit de modification d'action (Chef → Directeur → Planif → DG)

### Demande utilisateur

> *"JE NE VEUX PLUS QUE L'ACTION SOIT MODIFIABLE APRÈS ENREGISTREMENT CAR SA MODIFICATION
> NE SERA QUE SOUS DEMANDE DE REPORT D'ÉCHÉANCE PAR LE CHEF DE SERVICE AVEC JUSTIFICATIF
> ADRESSÉE À SON DIRECTEUR QUI VA TRANSFÉRER À LA PLANIFICATION ET À LA DG POUR ACCORD OU
> REJET MOTIVÉ ET SI ELLE ACCORDE L'ACTION POURRA REVENIR EN ÉCRITURE..."*

### Circuit implémenté

```
Action enregistrée au PTA → FIGÉE
   ↓
Chef de service/unité : « Demande de modification » (+ justificatif optionnel)
   ↓ statut = soumise
Directeur de la direction : transfère
   ↓ statut = transmise
Planification : avis CONSULTATIF (favorable / défavorable)
   ↓ (l'avis ne tranche pas)
DG : décision (approuver / rejeter motivé)
   ↓ si approuvé
Action DÉVERROUILLÉE → modifiable par le chef au niveau du PTA
```

### Changements

1. **Migration** : `planning_unlock_requests` + `transferred_by/at`, `transfer_comment`,
   `planif_avis/_by/_at`, `planif_comment`, `justificatif_path`.
2. **`PlanningUnlockRequest`** : statut `transmise`, constantes `AVIS_*`, relations
   `transferredBy` / `planifReviewer`.
3. **`PlanningModificationLockService`** :
   - `requestUnlock` notifie désormais le **directeur** (plus le DG directement) + justificatif.
   - Nouveau `transferByDirecteur` (→ notifie Planif + DG).
   - Nouveau `recordPlanifAvis` (avis consultatif → notifie DG).
   - `approve` / `reject` exigent le statut `transmise` (le DG ne peut décider qu'après transfert).
   - `canTransfer` (directeur de la direction), `canGivePlanifAvis` (Planification).
   - **`canBypassLock` : une ACTION verrouillée n'est plus modifiable par le chef/direction**
     — bypass conservé uniquement pour PAS/PTA. L'action exige le circuit.
4. **`PlanningUnlockWebController`** : `transferByDirecteur`, `reviewByPlanification`,
   upload justificatif dans `storeForTarget`.
5. **Routes** : `planning-unlocks.transfer`, `planning-unlocks.planif` (+ `.dg` existante).
6. **UI** : page « Demandes de modification » refondue (suivi du circuit + bouton par étape
   selon rôle/statut). Texte du bouton « Demande de modification » du PTA mis à jour.

### Tests
- `PlanningUnlockCircuitV2Test` : 3 verts (circuit complet, ordre imposé, périmètre directeur).
- `PlanningModificationLockWorkflowTest` : test mis à jour (chef ne peut plus modifier une
  action verrouillée → 409).

### Justificatif depuis le PTA (modal)
- Le bouton « Demande de modification » du PTA ouvre désormais un **modal** (motif obligatoire
  + justificatif fichier optionnel) au lieu d'un simple prompt. Envoi AJAX multipart vers
  `actions.unlock-requests.store`. Le justificatif est stocké chiffré (`unlock-requests/Y/m`).
- Modal accessible (role=dialog, aria-modal), fermeture par overlay/Annuler, dark mode.
  CSS `.modif-modal-overlay` / `.modif-modal-card` dans app.css.

---

## 2026-05-31 — Workflow de suivi V2 : reconstruction (P1 → P5 + UI)

> Branche `feature/reset-action-workflow`. Spec : docs/WORKFLOW-SUIVI-V2.md.
> Reconstruction du workflow de suivi après le reset, selon l'architecture co-validée.

### P1 — Migration BDD
- `actions` : + `type_action`, `requires_comment`, `allows_difficulty`, `official_progress_percent`.
- `sous_actions` : + `sub_action_type`, `weight`, `requires_proof`, `requires_comment`,
  `allows_difficulty`, `official_progress_percent`, `validation_status`.
- Mapping auto des 66 actions existantes : 65 quantitative + 1 composée.
  Migration réversible.

### P2 — Modèles + calculateur
- `Action` : constantes `TYPE_QUANTITATIVE|NON_QUANTITATIVE|COMPOSEE`, helpers
  `resolvedTypeAction()`, `isQuantitative/isNonQuantitative/isComposee`, `typeActionLabel()`.
- `SousAction` : `TYPE_*`, `VALIDATION_*`, `resolvedType()`, `isQuantitative()`.
- **`App\Services\Workflow\ActionPerformanceCalculator`** (service PUR, sans BDD) :
  perf provisoire par type, paliers (critique<50 / alerte<80 / acceptable<100 / atteinte / dépassée),
  statut temporel, conformité, calcul pondéré composée. **19 tests unitaires.**

### P3 — Formulaire PTA V2
- Select **type_action** pilote (mappé vers mode_evaluation en backend).
- Cases conformité action : commentaire obligatoire, champ difficulté.
- Sous-actions enrichies : type, **poids %**, justificatif/commentaire/difficulté + compteur Σ poids live.
- JS : affichage conditionnel par type + compteur poids (vert si =100%).
- Validation backend (StorePtaRequest + UpdatePtaRequest + upsertActionInline) :
  mapping, normalisation, **Σ poids = 100% obligatoire**.
- Persistance : syncPtaActions + syncPlannedSubActions enrichis.

### P4/P5 — Suivi opérationnel (backend + UI)
- **`App\Services\Workflow\ActionWorkflowService`** : orchestrateur du cycle
  save → submit → validate.
  - `recordActionProgress` / `recordSubActionProgress` : brouillon + perf provisoire.
  - `submitAction` / `submitSubAction` : conformité vérifiée → soumise_chef.
  - `reviewAction` / `reviewSubAction` : valider (officialise `official_progress_percent`) / rejeter (motif).
  - `refreshCompositeParent` : action composée clôturée AUTO quand toutes sous-actions validées
    (perf = Σ pondérée).
- `ActionTrackingWebController` : méthodes `updateActionProgress`, `updateSubActionProgress`,
  `reviewItem` + helpers d'autorisation V2 (`canTrackAction`, `canTrackSubAction`, `canReviewByChef`).
- Routes : `actions.execution.update`, `actions.sub-actions.update`, `actions.review` réactivées
  (les routes legacy non ré-implémentées restent en 410).
- **UI** (suivi.blade.php) : section « Suivi de l'action » avec perf officielle (en avant) +
  provisoire, badges type/perf/temporel, formulaire agent (simple ou par sous-action),
  validation chef par sous-action ou globale. Saisie quantitative en **remplacement** (total à ce jour).

### Décisions UX validées
- Affichage : 2 performances, officielle en avant.
- Action composée : validation par sous-action → clôture auto du parent.
- Saisie quantitative : remplacement (quantité totale à ce jour).

### Tests
- 19 unit (calculateur) + 7 feature (cycle V2 + rendu UI) + 20 PTA.
- Suite Feature complète : objectif 0 régression (en cours de vérification).

### Reste à faire
- P7 : Reporting → basculer les stats consolidées sur `official_progress_percent`.
- P8 : Recette visuelle utilisateur (navigateur) + tests E2E complémentaires.

---

## 2026-05-31 — RESET COMPLET du workflow de suivi action/sous-action (branche dédiée)

### Demande utilisateur

> *"JE VEUX QUE TU SUPRIME TOUTE LA LOGIQUE DU WORKFLO DE SUIVI DE L'ACTION OU SOUS ACTION
> DE TOUT TYPE ET ON VA LA REFAIR PROMENT TOI E MOI"*

Travail effectué sur **branche `feature/reset-action-workflow`** (main intact).

### Périmètre

**SUPPRIMÉ :**
- Saisie de progression quantitative (parent action)
- Saisie + soumission des sous-actions par l'agent
- Validation chef de service (sous-action + clôture action)
- Validation direction (déjà supprimée dans une session précédente, stubs nettoyés)
- Signalement et résolution d'anomalies
- Demandes de report d'échéance (création + avis SCIQ + décision DG)
- Bascule auto vers chef à la saisie

**PRÉSERVÉ (intact) :**
- Création / édition d'action via le formulaire PTA
- Workflow stratégique PAS → PAO → PTA (approbations)
- Workflow financement DAF → DG (séparé du suivi)
- Téléchargement / preview des justificatifs
- Fil de discussion / commentaires sur l'action
- Infrastructure notifications (BrevoMailService, NotificationPolicySettings,
  WorkspaceNotificationService)
- Modèles + colonnes BDD (Action, SousAction, ActionLog, etc.)

### Code supprimé / nettoyé

| Fichier | Avant | Après | Δ |
|---|---|---|---|
| `app/Http/Controllers/Web/ActionTrackingWebController.php` | 1625 | 527 | -1098 |
| `app/Services/Actions/ActionTrackingService.php` | 1660 | 1319 | -341 |
| `app/Services/Actions/DeadlineExtensionRequestService.php` | 245 | **supprimé** | -245 |
| `app/Http/Controllers/Api/ActionValidationController.php` | 120 | 25 (stub 410) | -95 |
| `resources/views/workspace/actions/suivi.blade.php` | 1227 | 736 | -491 |
| Routes web tracking (web.php) | 14 routes | 1 active + 10 stubs 410 | nettoyé |
| Tests obsolètes supprimés | — | — | -1763 |

**Tests supprimés (4 fichiers) :**
- `tests/Feature/ActionWorkflowSecurityTest.php` (1078 lignes)
- `tests/Feature/ActionNotificationWorkflowTest.php` (180)
- `tests/Feature/DeadlineExtensionRequestWorkflowTest.php` (193)
- `tests/Feature/ActionAnomalyAlertWorkflowTest.php` (312)

**Test ajusté :**
- `tests/Feature/ActionFinancingWorkflowTest.php` : remplacement des appels
  `reviewClosureByChef` par des `forceFill(financement_statut)` pour préserver
  l'initialisation du scénario financement.

### Routes (web)

Routes actives (workspace.actions.*) :
- `GET suivi` → page lecture seule
- `POST commentaires` → fil discussion
- `POST financement/daf`, `POST financement/daf/statut`, `POST financement/dg`
- `GET justificatifs/{j}/download`, `GET justificatifs/{j}/preview`

Routes stubs (retournent **HTTP 410 Gone** avec message explicite) :
- `sous-actions/{sa}` (update + review)
- `execution`, `review`, `review-direction`
- `anomalies` (signal + resolve)
- `reports-echeance` + `reports-echeance/{r}/sciq` + `/dg`
- `semaines/{w}/soumettre` (legacy weekly)

### Reset BDD effectué

```
Actions reset       : 66 (libellés/dates/responsables intacts)
Sous-actions reset  : 2  (libellés/dates intacts)
Justificatifs delete: 0  (aucune pièce de catégorie suivi en base)
ActionLogs delete   : 4  (traces des tests de notification)
deadline_extension_requests : vidée
```

Champs Action reset à valeurs initiales :
`statut='non_demarre'`, `statut_dynamique='non_demarre'`, `statut_validation='non_soumise'`,
`statut_parametrage='a_parametrer'`, `quantite_realisee=0`, `progression_*=0`,
`date_fin_reelle=NULL`, `soumise_le=NULL`, `evalue_le=NULL`, `evalue_par=NULL`,
`rapport_final=NULL`, `difficultes_rencontrees=NULL`, `motif_validation_chef=NULL`.

Champs SousAction reset :
`statut='non_demarre'`, `est_effectuee=false`, `date_realisation=NULL`, `completed_at=NULL`,
`quantite_realisee=0`, `taux_realisation=0`, `taux_execution=0`, `resultat_obtenu=NULL`,
`commentaire=NULL`.

### Validation

- Lint PHP (4 fichiers touchés) : 0 erreur
- Vite build : ✓ 17.76s, 0 erreur
- Suite Feature complète : en cours d'exécution

### À faire (suite logique)

La refonte du workflow opérationnel se fera sur cette même branche
`feature/reset-action-workflow` à travers une nouvelle spec à co-construire :
1. Spec métier détaillée (états, transitions, règles, qui fait quoi quand)
2. Migration / nouvelles colonnes si besoin
3. Implémentation incrémentale (controllers → services → vues → tests)
4. Merge dans main après recette utilisateur

---

## 2026-05-31 — Justificatif TOUJOURS obligatoire à la soumission (tous types)

### Demande utilisateur

> *"LES PIECE JUSTIFICATIVE RESTRE TOUJOUR OBLIGATOIR POUR TOUT TYDE D'ACTION OU SOUS ACTOIN"*

### Avant

La validation conditionnait le justificatif à `$submissionRequirements['proof']` :
- Sous-action : `requiredIf($isSubmit && $submissionRequirements['proof'] && ! $hasJustificatif)`
- Action quantitative : `requiredIf($isSubmit && $submissionRequirements['proof'] && ! $hasExistingProof)`

→ Certains types d'action/sous-action où `proof=false` permettaient la soumission sans aucune
pièce. Pas conforme à la règle métier ANBG.

### Après

Justificatif **toujours requis à la soumission**, indépendamment du type, sauf si une pièce
a déjà été déposée précédemment :

```php
// app/Http/Controllers/Web/ActionTrackingWebController.php
'justificatif' => [
    Rule::requiredIf($isSubmit && ! $hasJustificatif),
    'nullable', 'file', ...
],
'justificatif_quantitatif' => [
    Rule::requiredIf($isSubmit && ! $hasExistingProof),
    'nullable', 'file', ...
],
```

### UI mise à jour (suivi.blade.php)

Les deux formulaires (sous-action + action quantitative) affichent désormais le champ
pièce justificative avec :
- **Astérisque rouge** `*` sur le label
- Tag `(obligatoire à la soumission)`
- **Indicateur visuel dynamique** :
  - Si pièce(s) déjà déposée(s) → texte vert `✓ X pièce(s) déjà déposée(s) — vous pouvez soumettre sans en ajouter une nouvelle.`
  - Sinon → texte gris `Aucune pièce déposée. Une pièce est requise pour soumettre.`
- Slot `@error('justificatif')` pour afficher l'erreur de validation côté champ.

### Règles consolidées (référence)

| Champ | Save | Submit |
|---|---|---|
| Quantité réalisée | ⚪ optionnel | 🔴 requis (si type quantitatif) |
| Difficultés rencontrées | ⚪ optionnel | 🔴 requis (si type qualitatif/mixte) |
| **Pièce justificative** | ⚪ optionnel | 🔴 **TOUJOURS requis** (sauf si déjà déposée) |
| Commentaire de réalisation | ⚪ optionnel | ⚪ optionnel |
| Résultat obtenu | ⚪ optionnel | ⚪ optionnel |

### Validation

- Tests ciblés (ActionWorkflowSecurity, ActionTracking, sub_action) : 56 passés ✓
- Suite Feature complète : **318 passés, 3 skipped, 0 régression** (2222 assertions, 278s)

---

## 2026-05-30 — Audit & renforcement sécurité + design (vagues 1-3)

### Demande utilisateur

> *"fais le tous"* (suite à la liste des améliorations sécurité + design).

### Vague 1+2 — Sécurité (audit + corrections ciblées)

**Bonne nouvelle :** le projet avait déjà énormément de hardening en place. La majorité du
travail a consisté à vérifier l'existant plutôt qu'à ajouter du code.

| Item | État | Action |
|---|---|---|
| Security Headers (CSP, X-Frame, HSTS, COOP, etc.) | ✅ déjà actif | `AddSecurityHeaders` middleware enregistré sur web + api |
| Rate limiting login + API | ✅ déjà actif | `login`, `api-login`, `api`, `api-downloads` configurés dans `AppServiceProvider` (5/10min user+IP, 25/10min IP) |
| Force password change premier login | ✅ déjà actif | `EnsurePasswordIsFresh` + `PasswordPolicyService::isExpired()` détecte `password_changed_at = NULL`. **Corrigé user 102** : reset à NULL pour forcer changement à 1ère connexion |
| Validation uploads (MIME + taille + antivirus + chiffrement) | ✅ déjà actif | `DocumentPolicySettings::mimesRule()/maxUploadKilobytes()`, `SecureJustificatifStorage` chiffre + UUID + nosniff, `AntivirusScanner` ClamAV fail-closed en prod |
| Audit logs — exclusion champs sensibles | ✅ déjà actif | `SuperAdminWebController` strip `password` via `Arr::except`, User model `$hidden = ['password', ...]` |
| **`BREVO_API_VERIFY_SSL` en prod** | ✅ corrigé | `.env.example` + `.env.production.example` à jour avec avertissement explicite "TOUJOURS true en prod" |
| Policy mots de passe (12 chars, mixed case, symboles, pwned check, history 5, expiry 90j) | ✅ déjà actif | `config/security.php` |

### Vague 3 — Design (cleanup + UI feedback + a11y)

#### Cleanup
- Supprimé 5 mockups exploratoires HTML : `mockup_01_executive_dark.html`,
  `mockup_02_glass_premium.html`, `mockup_03_data_pro.html`,
  `public/mockups-glass-pas.html`, `public/mockups-premium-pas.html` (215 KB libérés).

#### Spinners + top progress bar
**Fichier :** `resources/js/ui-enhancements.js`
- Le mécanisme de disable submit existait → **enrichi avec un vrai spinner SVG inline** +
  remplacement du texte du bouton par "Envoi en cours…" + `aria-busy=true` pour les
  lecteurs d'écran.
- **Nouveau : top-bar progress** (style nprogress) wrappant `window.fetch()` → barre bleue
  de 3px en haut de page pendant les calls AJAX (commentaires inline, save action, etc.).

**Fichier :** `resources/css/app.css`
- Classes `.ui-spinner`, `.ui-topbar-progress`, `.is-loading`, `.is-submitting`
  avec animation `ui-spin` (0.7s linear) et `ui-topbar-slide` (1.4s).
- Respecte `prefers-reduced-motion` (désactive les animations).

#### Responsive Bento (charts dashboard)
**Fichier :** `public/css/anbg-glass.css`
- **Bug fix :** la media query `< 1100px` ne collapsait que le grid parent `.charts-bento`
  mais pas les sous-grids `.charts-bento-row-hero/trend/rank` qui restaient en 2 colonnes.
  Maintenant les 3 rows passent en 1 colonne aussi.
- **Nouveau breakpoint `< 768px`** : padding et border-radius réduits, taille du score
  hero réduite (2.5rem au lieu de 4rem) → plus de scroll horizontal sur mobile.

#### Accessibilité
**Fichier :** `resources/css/app.css`
- `*:focus-visible` global : outline bleu 2px avec offset 2px sur tous les éléments
  interactifs (light + dark mode adaptés).
- **Skip-link** déjà branché dans le layout admin (`.skip-to-content` ligne 202 de
  `resources/views/layouts/admin.blade.php`), vérifié non régressé.
- Tooltip auto pour les `button[aria-label]:not([title])` au focus (rend visible
  les libellés des boutons-icônes seuls).
- **Système typographique 5 niveaux** : classes `.ui-typo-h1`, `.ui-typo-h2`, `.ui-typo-h3`,
  `.ui-typo-h4`, `.ui-typo-caption` avec tailles + letter-spacing + couleurs cohérents
  (dark mode adapté pour caption).

### Vague 4 — 2FA : **différé**

Un 2FA correct nécessite : migration colonnes chiffrées + package
`pragmarx/google2fa-laravel` + routes (setup/verify/disable/recovery) + 5 pages UI +
middleware d'enforcement post-login + tests. **Estimation : 1-2 jours dédiés**, hors scope
de cette série. À planifier en session dédiée.

### Build + tests

- Vite build : ✓ (19.27s, 0 erreur)
- Suite Feature : à vérifier ci-dessous

---

## 2026-05-30 — Migration SMTP → API HTTP Brevo (résout les blocages IP dynamique) ✓

### Demande utilisateur

> *"pourquoi je doit toujour autoriser une nouvelle adresse"*

Après le premier envoi SMTP réussi, l'IP de l'utilisateur (FAI Gabon, IP dynamique) changeait à
chaque connexion, obligeant à ré-autoriser l'IP dans Brevo à chaque test. Inutilisable.

### Décision

Migrer le canal email de **SMTP** vers **API HTTP** Brevo. L'API HTTP s'authentifie par
`BREVO_API_KEY` au lieu d'user/pass SMTP, plus simple et plus rapide.

### Code modifié

| Fichier | Changement |
|---|---|
| `config/services.php` | Nouvelles clés `brevo.transport` (api/smtp), `brevo.api_key`, `brevo.api_endpoint`, `brevo.api_timeout`, `brevo.api_verify_ssl` |
| `app/Services/Notifications/BrevoMailService.php` | Ajout `sendViaApi()` (POST https://api.brevo.com/v3/smtp/email + render Blade) ; `sendViaSmtp()` extrait ; routage via `transport()` ; `canSendEmails()` vérifie `api_key` si transport=api |
| `tests/Feature/BrevoEmailChannelTest.php` | Forcé `transport='smtp'` pour préserver le scénario fail-safe SMTP existant |
| `tests/Feature/SuperAdminNotificationsSmokeTest.php` | Nouveau test API HTTP avec `Http::fake()` (vérifie endpoint, header `api-key`, body JSON, tags) |

### Tests

- 4/4 verts sur `SuperAdminNotificationsSmokeTest`
- **318 passés, 3 skipped, 0 failed** sur la suite Feature complète (2223 assertions)

### Galère côté config Brevo (à savoir pour la suite)

Brevo a **3 niveaux** d'IP whitelist indépendants — il faut les désactiver tous pour un backend
avec IP dynamique :

1. **Toggle compte → SMTP** : `https://app.brevo.com/security/authorised_ips` (section "Blocage IP")
2. **Toggle compte → API** : MÊME page, switch séparé (section "Blocage IP" → ligne "Clés API")
3. **Restriction par clé** : Sur la création de chaque clé, option "Restreindre par IP"

Pour notre cas : il a fallu **désactiver le toggle "Clés API"** (qui était par défaut **Activé**),
plus créer une clé sans restriction par-clé. Le toggle SMTP était déjà désactivé.

### Envoi réel — preuve du succès

```
LOG brevo_email_log id=13
  status      = sent ✓
  recipient   = carlngomayilla70@gmail.com
  sent_at     = 2026-05-30 19:37:36
  subject     = Nouvelle action attribuée
  transport   = api
  durée totale = 999 ms (vs 2-3s en SMTP)
```

### Note dev local Windows

PHP CLI Windows n'a souvent pas de bundle CA cert configuré (`curl.cainfo` vide), provoquant
`cURL error 60: SSL certificate ... unable to get local issuer certificate`. Ajout du flag
`BREVO_API_VERIFY_SSL=false` (à n'utiliser **qu'en dev local**) pour bypass.

En production, laisser `BREVO_API_VERIFY_SSL=true` (défaut) et s'assurer que PHP a accès
à un bundle CA valide.

### Configuration `.env` finale (template)

```env
# Transport actif
BREVO_TRANSPORT=api
BREVO_ENABLED=true
BREVO_API_KEY=xkeysib-...                  # https://app.brevo.com/settings/keys/api
BREVO_API_VERIFY_SSL=false                 # DEV ONLY (Windows sans bundle CA) — true en prod

# Expéditeur
BREVO_FROM_ADDRESS=carlngomayilla70@gmail.com
BREVO_FROM_NAME="ANBG · e-Pilotage PAS"

# Les anciennes credentials SMTP peuvent rester pour fallback transport=smtp
# mais ne sont PAS utilisées tant que BREVO_TRANSPORT=api.
```

---

## 2026-05-30 — Test E2E envoi email réel via Brevo (canal email actif) ✓

### Demande utilisateur

> *"oui à l'admin fonctionnel car c'est seule email fonctionnel actuellement"* → cible : `carlngomayilla70@gmail.com`

### Setup

| Élément | Action |
|---|---|
| `users.id=102` | Créé : `carlngomayilla70@gmail.com`, role=`super_admin`, `is_active=true` |
| Login provisoire | `email=carlngomayilla70@gmail.com` / `password=ChangeMe-Now-2026!` |
| Template `action_assigned` en BDD | Re-seedé via `NotificationPolicySettingsSeeder` → channels passés de `['in_app']` à `['in_app', 'email']`, message accentué + guillemets « » |
| Action `id=660` | Pivot `action_responsables` réassigné temporairement à user 102 → restauré après le test |

### Premier envoi (échec)

```
brevo_email_log id=4
  status = failed
  error  = 525 5.7.1 Unauthorized IP address
```

Cause : l'IP publique de la machine (`154.116.105.28`) n'était pas dans la liste blanche
Brevo (https://app.brevo.com/security/authorised_ips). Utilisateur a ajouté l'IP.

### Deuxième envoi (succès)

```
brevo_email_log id=5
  status      = sent ✓
  recipient   = carlngomayilla70@gmail.com
  sent_at     = 2026-05-30 18:26:39
  error       = NULL
```

Sujet attendu côté boîte : `[ANBG] Nouvelle action attribuée`
Message : *"L'action « Produire un reporting global automatisé par direction » vous a été
attribuée. Consultez-la dès maintenant."*

### Découverte importante (effet de bord)

Le re-seed `NotificationPolicySettingsSeeder` était **nécessaire** : les templates stockés en
BDD étaient les anciennes versions (channels `['in_app']` seul, message sans accents corrects,
guillemets ASCII `"X"`). Sans le re-seed, les changements de
`NotificationPolicySettings::eventTemplateDefaults()` n'auraient pas pris effet pour
l'utilisateur final.

→ **Pour tout déploiement futur des nouveaux libellés en prod** : lancer
`php artisan db:seed --class=NotificationPolicySettingsSeeder --force` après le déploiement,
puis `app(NotificationPolicySettings::class)->flush()` pour vider le cache mémoire.

### Configuration Brevo opérationnelle

```env
# .env (l'utilisateur l'a configuré lui-même)
MAIL_MAILER=brevo
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=abfe7e001@smtp-brevo.com
MAIL_PASSWORD=***
MAIL_FROM_ADDRESS=carlngomayilla70@gmail.com
MAIL_FROM_NAME="ANBG · e-Pilotage PAS"
BREVO_ENABLED=true
```

---

## 2026-05-30 — Décalage des 11 échéances passées à J+30 (2026-06-29)

### Demande utilisateur

> *"MODIFI LES ECHEANCES DES ACTION QUI SONT INFERIEUR OU EGALE A LA DATE DE AUJOURD'HUI"*
> Choix d'offset confirmé : **+30 jours**.

### Diagnostic

Au 2026-05-30, **11 actions** avaient `date_echeance <= today`, toutes au 2026-04-30 (un mois en
retard) avec `date_fin = date_echeance` :

```
IDs : 595, 601, 607, 613, 619, 625, 631, 637, 643, 649, 655
Statut : non_demarre / non_demarre  (aucune n'avait commencé)
```

Aucune `ActionWeek` rattachée, aucune `SousAction` avec `echeance` propre → pas de cascade
nécessaire sur ces entités.

### Action appliquée

Update SQL (single transaction, via Eloquent) sur les 2 champs cohérents `date_echeance` ET
`date_fin` pour qu'ils restent alignés :

```php
App\Models\Action::query()
    ->whereIn('id', $ids)
    ->update([
        'date_echeance' => '2026-06-29 00:00:00',
        'date_fin'      => '2026-06-29 00:00:00',
    ]);
// → 11 lignes mises à jour
```

### Vérification

- 11/11 actions affichent maintenant `fin = ech = 2026-06-29` ✓
- Actions restantes avec `date_echeance <= aujourd'hui` : **0** ✓

### Note

Le `date_debut` (2026-01-15) reste inchangé — les actions ont simplement vu leur fenêtre
prolongée d'environ 2 mois (de 2026-04-30 à 2026-06-29). Aucune notification d'alerte
"échéance proche" n'a été déclenchée (mise à jour silencieuse en BDD, hors workflow métier).

---

## 2026-05-30 — Personnalisation des notifications + alertes + flash messages (+ test Super Admin)

### Demande utilisateur

> *"MET A JOUR LES NOTIFICATION, ET ALERTE ET FAIS UN ESSAI AVEC LE SUPER ADMIN"*

Suite à la demande précédente sur la colonne `Objectif opérationnel` du reporting (déjà corrigée).

### Feature 1 — Personnalisation des libellés de notifications

**Avant** : titres et messages avec accents manquants ("attribuee", "validee", "creee"), ton télégraphique
("L action X attend votre evaluation."), parfois template vide.

**Après** : accents UTF-8 propres, ton plus naturel et orienté action, format français typographique
(« guillemets », apostrophes courbes).

#### Fichiers touchés

1. **`app/Services/NotificationPolicySettings.php`** (lignes 502-527) — **source de vérité** des libellés
   user-facing. Les 26 templates `eventTemplateDefaults()` ont tous été repris :
   - `action_assigned` : *"L'action « X » vous a été attribuée. Consultez-la dès maintenant."*
   - `action_submitted_to_chef` : *"L'action « X » vient d'être soumise par l'agent et attend votre évaluation."*
   - `action_submitted_to_direction` : nouveau titre `"Action transmise à la direction"` (était vide)
   - `action_reviewed_by_chef` / `_by_direction` : nouveaux titres `"Décision du chef de service"` / `"Décision de la direction"` (étaient vides)
   - `action_finalized_by_chef` : *"L'action « X » a été finalisée par le chef de service, sans étape direction supplémentaire."*
   - `action_finalized_without_workflow` : ton confirmé "clôturée"
   - `action_alert_escalation` : nouveau titre `"Alerte sur une action"` + format `"Niveau {level} — {message}"`
   - `action_financing_requested` : *"Demande de financement à instruire"*
   - `action_financing_reviewed_by_daf` / `_by_dg` : nouveaux titres `"Décision DAF / DG sur le financement"`
   - `pao_transmitted_to_service` : *"Un nouveau PAO vient d'être transmis... Préparez votre PTA."*
   - `pao_updated_for_service` : *"Vérifiez les ajustements."*
   - `pta_created_to_direction` : *"vient de créer son PTA"*
   - `pta_submitted_for_validation` : **bug corrigé** — "actualise" sans accent → *"vient d'actualiser son PTA"*
   - `pta_reviewed_by_direction` : nouveau titre `"Décision direction sur le PTA"` (était vide)
   - `sub_action_completed` : *"Sous-action terminée — à vérifier"* / *"Elle attend votre validation."*
   - `justificatif_added` : *"Pièce justificative ajoutée"*
   - `deadline_extension_*` : tous reformulés avec accents complets et "demande de report d'échéance"
   - `pas_status` / `pao_status` / `pta_status` : nouveaux titres `"Mise à jour PAS/PAO/PTA"` (étaient vides)
   - `delegation_created` : reformulation *"Une délégation vous a été attribuée par X sur le périmètre Y."*

2. **`app/Services/Notifications/WorkspaceNotificationService.php`** — defaults (~25 blocs `dispatchEvent`)
   et helpers (`resolveStatusPayload`, `serviceLabel`, `directionLabel`) accentués pour cohérence avec
   les templates ci-dessus. Plus replace_all `'non renseigne'` → `'non renseigné'` et
   `'non renseignee'` → `'non renseignée'`.

### Feature 2 — Personnalisation des flash messages des controllers

**124 remplacements** ciblés via script PowerShell (UTF-8 sans BOM, escape PHP `\'` propre) sur
13 controllers Web. Patterns sûrs qui n'apparaissent que dans des chaînes user-facing
(p. ex. `' avec succes.'` → `' avec succès.'`).

#### Fichiers touchés (avec compteur)

| Fichier                                      | Remplacements |
|----------------------------------------------|---------------|
| ActionWebController.php                      | 3             |
| ActionTrackingWebController.php              | 8             |
| GovernanceWebController.php                  | 2             |
| NotificationWebController.php                | 1             |
| PasWebController.php                         | 6             |
| MonitoringWebController.php                  | 1             |
| PaoWebController.php                         | 6             |
| PtaWebController.php                         | 8             |
| MessagingWebController.php                   | 1             |
| PlanningUnlockWebController.php              | 2             |
| **SuperAdminWebController.php**              | **72**        |
| ProfileWebController.php                     | 5             |
| ReferentielWebController.php                 | 9             |
| **TOTAL**                                    | **124**       |

#### Exemples concrets

```diff
- ->with('success', 'Direction creee avec succes.');
+ ->with('success', 'Direction créée avec succès.');

- ->with('success', 'Decision DG enregistree.');
+ ->with('success', 'Décision DG enregistrée.');

- ->with('success', 'PTA cloture avec rapport d anomalies trace.');
+ ->with('success', 'PTA clôturé avec rapport d\'anomalies tracé.');

- ->with('success', 'Toutes les notifications ont ete marquees comme lues.');
+ ->with('success', 'Toutes les notifications ont été marquées comme lues.');
```

#### Incident résolu pendant l'implémentation

Premier essai du script PowerShell : `''` (escape style SQL) passé à PowerShell devenait `''`
dans le PHP, ce qui est interprété comme "deux strings vides" dans un single-quote PHP → 11 parse
errors. Réparé en revertant les 13 controllers via `git checkout` puis en relançant avec
`"\'"` (escape PHP) dans les strings PS double-quoted. **0 parse error** au final.

### Feature 3 — Test E2E avec le Super Admin

Création de **`tests/Feature/SuperAdminNotificationsSmokeTest.php`** avec deux tests :

1. **`test_super_admin_flow_workspace_and_notifications`** : log Super Admin → GET sur les 5 routes
   principales (`workspace.index`, `notifications.index`, `pas.index`, `pao.index`, `pta.index`)
   → résolution du `WorkspaceNotificationService` depuis le container.
2. **`test_action_assigned_notification_uses_personalized_french_label`** : crée Direction →
   Service → Agent → PAS → PAO → PTA → Action, déclenche `notifyActionAssigned()`, assertions :
   - titre = `"Nouvelle action attribuée"`
   - message contient `"Cartographier les zones humides"`
   - message contient `"été attribuée"` (vérifie l'accent)
   - message contient `"« "` (vérifie les guillemets typographiques)

**Résultat :** `2 passed (7 assertions)` ✓

```
PASS  Tests\Feature\SuperAdminNotificationsSmokeTest
  ✓ super admin flow workspace and notifications              7.16s
  ✓ action assigned notification uses personalized french label  0.11s
```

### Validation

- `php -l` sur les 14 fichiers PHP modifiés : **0 erreur de syntaxe**
- Tests dédiés Super Admin : **2/2 verts**
- Régression : suite Feature complète en cours

### Action utilisateur attendue

Au prochain événement métier (attribution d'action, validation, soumission PTA, etc.) :
- **Notif in-app** dans la cloche : doit afficher les nouveaux libellés (« » + accents)
- **Toast/flash** après une création/modif : doit afficher les versions accentuées

Si une zone d'UI affiche encore du texte sans accents ou non personnalisé, envoyer une capture
pour traitement ciblé.

---

## 2026-05-29 — Workflow ANBG : actions figees apres enregistrement + bouton "Demande de modification" + notif RMO post-parametrage

### Feature 1 — Gel des actions enregistrees + workflow de demande de modification

**Demande utilisateur :** *"Normalement lorsqu'une action est enregistree, tous les champs de cette action doivent se figer et a la place du bouton Enregistrer on doit avoir le bouton de Demande de modification qui va chez le DG et le service Planification pour accord. Sous mandat du DG, l'action se deverrouille et les champs ne seront plus grises."*

**Implementation :**

Une action est consideree comme **figee** si :
- elle a un id (deja persistee en BDD)
- son `statut_parametrage = 'parametre'` (chef de service a fini son travail)
- elle a un `modification_locked_at` non null
- pas de `modification_unlocked_at` actif (le DG n'a pas approuve la modification)
- l'utilisateur courant n'est ni SA, ni DG, ni Planification, ni SCIQ (ces roles voient toujours Enregistrer)

**UI :**
- Badge `🔒 Enregistree` ajoute au header du bloc action
- Bandeau jaune en haut du body : "Action enregistree et figee. Utilisez Demande de modification."
- Body entoure d'un `<fieldset disabled>` → tous les champs en lecture seule + opacite reduite
- Le bouton vert "Enregistrer" est remplace par le bouton orange "Demande de modification"

**Workflow JS :**
- Clic "Demande de modification" → prompt motif (5 caracteres min)
- POST `/workspace/actions/{action}/demandes-deverrouillage` avec le motif
- Cree une `PlanningUnlockRequest` (statut pending)
- Notifie automatiquement les **DG + Planification + SCIQ** (regle metier ANBG)
- Le DG approuve → l'action est deverrouillee → la page rechargee montre les champs editables a nouveau

**Fichiers modifies :**
- [resources/views/workspace/pta/partials/action-form-block.blade.php](../resources/views/workspace/pta/partials/action-form-block.blade.php) — calcul `$isFrozen`, badge UI, bandeau d'info, fieldset disabled, swap bouton Enregistrer/Demande modification
- [resources/views/workspace/pta/form.blade.php](../resources/views/workspace/pta/form.blade.php) — handler JS `data-request-modification` (POST AJAX avec motif)
- [app/Http/Controllers/Web/PtaWebController.php](../app/Http/Controllers/Web/PtaWebController.php) — chargement des champs `modification_locked_at`, `modification_unlocked_at`, `modification_unlock_expires_at` et `statut_parametrage` dans `$actionRows`
- [app/Services/PlanningModificationLockService.php](../app/Services/PlanningModificationLockService.php) — `notifyDg` renomme en notification multi-destinataires : DG + Planification + SCIQ. Titre passe de "Demande de deverrouillage" a "Demande de modification".

### Feature 2 — Actions ne partent au RMO qu'apres parametrisation effective

**Demande utilisateur :** *"Les actions du PTA ne partent chez leur RMO que si elles ont ete parametrees et enregistrees."*

**Avant :** la notification `notifyActionAssigned` etait envoyee a chaque creation d'action (`$isNewAction = true`), y compris pour les actions IMPORTEES via Excel qui sortaient en `statut_parametrage = 'a_parametrer'`. Resultat : les RMO recevaient des notifications pour des actions incompletes.

**Apres :** la notification au(x) RMO est declenchee uniquement quand l'action passe effectivement en `statut_parametrage = 'parametre'` :
- Action nouvelle creee directement via le formulaire PTA (jamais importee) → notification (car le payload force `parametre`)
- Action importee en `a_parametrer`, le chef de service la parametre et clique Enregistrer → transition `a_parametrer → parametre` detectee → notification envoyee a ce moment-la

**Fichier modifie :**
[app/Http/Controllers/Web/PtaWebController.php](../app/Http/Controllers/Web/PtaWebController.php) `syncPtaActions` — capture du statut_parametrage AVANT le save via `getOriginal()`, comparaison apres save, declenchement conditionnel de `notifyActionAssigned` uniquement sur la bascule.

**Changement cle :**
```php
// AVANT
if ($isNewAction) {
    $trackingService->initializeActionTracking($action, $actor);
    $notificationService->notifyActionAssigned($action, $actor);  // ← inconditionnel
}

// APRES
$wasUnparametre = ! $isNewAction
    && (string) ($action->getOriginal('statut_parametrage') ?? '') === 'a_parametrer';

// (apres save)
$becameParametre = (string) ($action->statut_parametrage ?? '') === 'parametre';

if ($isNewAction && $becameParametre) {
    $trackingService->initializeActionTracking($action, $actor);
    $notificationService->notifyActionAssigned($action, $actor);
} elseif (! $isNewAction && $wasUnparametre && $becameParametre) {
    // Premiere bascule a_parametrer → parametre : notification au RMO.
    $notificationService->notifyActionAssigned($action, $actor);
}
```

### Verification (tinker)
- 3 actions actuellement parametrees + lockees → figees dans le formulaire PTA (badge 🔒, bouton Demande modification)
- 8 destinataires actifs pour la notification "Demande de modification" : 1 DG + 4 Planification + 3 SCIQ

### Tests
- **369 tests passent** (2408 assertions), 0 failed, 3 skipped ✓
- Aucune regression introduite

---

## 2026-05-29 — Bug fix : duplication des sous-actions a chaque save inline + acces utilisateurs pour Direction/Service

### Bug 1 — Duplication des sous-actions
**Symptome utilisateur (capture d'ecran fournie) :** l'agent voit 4 sous-actions au lieu d'1 dans le suivi de son action. Verification BDD : Action #545 avait 8 sous-actions (4 paires `Sous-action 1` + `Sous-action 2`, creees successivement a 19:18, 19:18, 19:23, 19:23).

**Cause racine :** la methode `syncPlannedSubActions` du PtaWebController ne supprimait jamais les sous-actions existantes hors payload. La JS d'enregistrement inline (`collectActionPayload`) renvoie tous les inputs dont les `[id]` cachees etaient vides pour les nouveaux blocs. Le controleur `upsertActionInline` ne retournait pas les ids des sous-actions creees, donc apres le 1er save, les hidden inputs DOM restaient a "" et le 2eme save creait a nouveau les memes sous-actions.

**Fix :**
1. [app/Http/Controllers/Web/PtaWebController.php](../app/Http/Controllers/Web/PtaWebController.php) — `syncPlannedSubActions` : etape preliminaire qui supprime les sous-actions existantes dont l'id n'est PAS dans le payload courant. Garantit que chaque save reflete exactement la liste envoyee, pas de fantome.
2. [app/Http/Controllers/Web/PtaWebController.php](../app/Http/Controllers/Web/PtaWebController.php) — `upsertActionInline` : retourne `action.sous_action_ids` (tableau des ids dans l'ordre BDD ASC) dans le JSON pour que le front sache mettre a jour les hidden inputs.
3. [resources/views/workspace/pta/form.blade.php](../resources/views/workspace/pta/form.blade.php) — handler `data-save-action` : selecteur strict `input[name="actions[N][id]"]` pour ne pas attraper aussi les inputs sous_actions[N][id], et boucle qui synchronise chaque hidden id de sous-action avec l'array retourne par le serveur. Apres save, les blocs DOM ont leurs ids → les saves suivants UPDATE au lieu de CREATE.

### Nettoyage one-shot des doublons existants
Script Tinker execute : Action #545 avait 8 sous-actions → reste 2 (les 2 originales preservees, 6 doublons supprimes via `forceDelete`).

### Bug 2 — 403 sur "Agents / RMO" pour Directeur et Chef de service
**Symptome (audit navigation) :** Directeur DAF et Chef ENB recevaient 403 en cliquant sur le module "Agents / RMO" / "Services / Agents" qui pointait vers `/workspace/referentiel/utilisateurs`. Verification : la methode `utilisateursIndex` exigeait `users.manage` ou `users.manage_roles`, permissions reservees aux roles admin / planification / SCIQ / DG / cabinet — pas a Direction / Service.

**Fix :**
[app/Http/Controllers/Web/ReferentielWebController.php](../app/Http/Controllers/Web/ReferentielWebController.php) `utilisateursIndex()` — gate `denyUnlessUserManager` remplace par `denyUnlessReferentielReader` (permission `referentiel.read`). Les operations d'ecriture (create / update / destroy) restent protegees par `denyUnlessUserManager`. La requete est deja scopee par direction (pour Direction) et service (pour ROLE_SERVICE) en interne — donc Chef ENB ne voit que les utilisateurs de son service, Directeur DAF que ceux de sa direction.

**Tests mis a jour :**
- [tests/Feature/SuperAdminRolePermissionsTest.php](../tests/Feature/SuperAdminRolePermissionsTest.php) `admin_loses_user_management_route_when_permissions_are_revoked` — desormais retire aussi `referentiel.read` et `referentiel.write` du payload pour bloquer effectivement l'admin.
- [tests/Feature/WebWorkspaceTest.php](../tests/Feature/WebWorkspaceTest.php) `user_role_management_access_follows_role_permissions` — distingue lecture (DG/Cabinet OK) et ecriture (DG/Cabinet forbidden), refletant le nouveau modele read/write split.

### Audit complet de l'application
| Profil | Modules autorisés | Modules accessibles (HTTP 200) | Bugs |
|---|---|---|---|
| Super Admin | 11 | 11 / 11 | aucun |
| DG | 7 | 7 / 7 | aucun |
| Directeur DAF | 9 | 9 / 9 | aucun (fix referentiel applique) |
| Chef ENB | 8 | 8 / 8 | aucun (fix referentiel applique) |
| SCIQ | 10 | 10 / 10 | aucun |
| Agent | 4 | 4 / 4 | aucun |

**Verifications structurales :**
- 305 routes enregistrees ✓
- 0 erreur de syntaxe PHP sur `app/` ✓
- Migrations a jour ✓
- **369 tests passent** (2408 assertions), 0 failed, 3 skipped ✓

---

## 2026-05-29 — Nettoyage : suppression organigramme + ancienne logique inutilisee

### Contexte
Demande utilisateur : *"Supprime toutes les anciennes logiques qui ne sont plus utilisees et supprime aussi l'organigramme."*

### Suppression de l'organigramme
**Code supprime / nettoye :**
- [resources/views/workspace/messaging/index.blade.php](../resources/views/workspace/messaging/index.blade.php) — section `<section id="messaging-orgchart">` retiree (~110 lignes Blade : filtres organigramme, toolbar, viewport, liste arborescente)
- [resources/views/workspace/messaging/partials/org-tree-node.blade.php](../resources/views/workspace/messaging/partials/org-tree-node.blade.php) — fichier supprime
- [app/Services/Messaging/MessagingDirectoryService.php](../app/Services/Messaging/MessagingDirectoryService.php) — methode `orgChart()` + helpers prives (`buildOrgTree`, `directionTreeNode`, `serviceTreeNode`, `userTreeNode`, `userTheme`, `sortUsersForOrgChart`, `userOrgRank`, `userHierarchyLevel`, `userFunctionRank`, `directionRank`, `serviceRank`) supprimes (~440 lignes). Imports `Direction`, `Service`, `Str` retires. Constantes `DIRECTION_ORDER` et `SERVICE_ORDER` supprimees.
- [app/Http/Controllers/Web/MessagingWebController.php](../app/Http/Controllers/Web/MessagingWebController.php) — variables `$orgFilters`, `$orgChart` retirees du payload et du `view()`.
- [resources/js/messaging-init.js](../resources/js/messaging-init.js) — fonction `initMessagingOrgTree()` (~425 lignes JS) et son appel supprimes.
- [resources/css/app.css](../resources/css/app.css) — section `/* Organigramme */` (260 lignes CSS `.messaging-org-*`) supprimee.
- [resources/views/layouts/admin.blade.php](../resources/views/layouts/admin.blade.php) — raccourci "Organigramme" du menu dropdown messagerie retire.
- [app/Services/UserProfileService.php](../app/Services/UserProfileService.php) — operation "Parcourir l organigramme" retiree de la liste des operations messagerie.
- [app/Services/RolePermissionSettings.php](../app/Services/RolePermissionSettings.php) — description permission `messagerie.read` mise a jour : "Acceder a la messagerie interne" au lieu de "Acceder a la messagerie et a l'organigramme".
- [resources/views/workspace/messaging/partials/profile-card.blade.php](../resources/views/workspace/messaging/partials/profile-card.blade.php) — texte empty-state mis a jour ("annuaire" au lieu de "organigramme").
- [tests/Unit/MessagingDirectoryServiceTest.php](../tests/Unit/MessagingDirectoryServiceTest.php) — fichier supprime (testait `orgChart`).
- [tests/Feature/MessagingWebTest.php](../tests/Feature/MessagingWebTest.php) — test `test_messaging_org_tree_displays_clickable_profile_nodes` renomme et reecrit en `test_messaging_page_loads_without_orgchart_section_after_removal` (verifie qu'aucun marqueur organigramme ne subsiste). Test `test_service_user_sees_only_allowed_contacts_in_messaging_directory` adapte aux nouveaux contacts visibles dans l'annuaire limite (avant : MATTEYA visible via orgchart ; maintenant : Directeur DAF visible dans la limite des 18 contacts alphabetiques).

### Suppression des routes legacy planning
- [routes/web.php](../routes/web.php) — routes stub no-op supprimees : `pas.submit`, `pas.approve`, `pas.lock`, `pas.reopen`, `pas-axes.legacy`, `pas-objectifs.legacy`, `pao.submit`, `pao.approve`, `pao.lock`, `pao.reopen`, `pta.submit`, `pta.approve`, `pta.lock`, `pta.reopen`. Toutes ces routes n'etaient que des closures retournant un message d'erreur — heritage de l'ancien workflow valide/soumis/approuve plus utilise. Le cycle reel est : `actif → cloture → archive` (PAS) et `en_cours/brouillon → cloture → archive` (PAO/PTA).
- [tests/Feature/DgReadOnlyRoleTest.php](../tests/Feature/DgReadOnlyRoleTest.php) — 3 tests legacy `test_dg_cannot_use_legacy_pas_approval_route`, `test_dg_cannot_use_legacy_pao_approval_route`, `test_admin_legacy_pas_approval_route_no_longer_changes_status` supprimes (les routes correspondantes n'existent plus).
- [tests/Feature/WebWorkspaceTest.php](../tests/Feature/WebWorkspaceTest.php) — assertions `/workspace/pas-axes` et `/workspace/pas-objectifs` mises a jour : `assertNotFound()` au lieu de `assertRedirect('/workspace/pas')`.

### Suppression du formulaire standalone Action
- [resources/views/workspace/actions/form.blade.php](../resources/views/workspace/actions/form.blade.php) — fichier supprime (567 lignes Blade). Le formulaire standalone d'edition / creation d'action n'etait plus servi : la route `workspace.actions.create` redirigeait deja vers PTA index (cf. web.php ligne 260) et `workspace.actions.edit` redirigeait deja vers le formulaire PTA avec ancre (cf. ActionWebController::edit).
- [resources/views/workspace/actions/partials/pta-style-edit-fields.blade.php](../resources/views/workspace/actions/partials/pta-style-edit-fields.blade.php) — fichier supprime (partial du formulaire standalone).
- [app/Http/Controllers/Web/ActionWebController.php](../app/Http/Controllers/Web/ActionWebController.php) — methode `create()` (~65 lignes) supprimee. Methode `edit()` simplifiee : 60 lignes → 18 lignes, fallback vue standalone retire, abort 404 si action orpheline sans pta_id.
- [tests/Feature/ActionFinancingFormFieldsTest.php](../tests/Feature/ActionFinancingFormFieldsTest.php) — test `test_financing_nature_field_is_visible_in_action_and_pta_forms` renomme `test_financing_nature_field_is_visible_in_pta_action_block`, assertions sur la vue standalone supprimees.

### Bilan
**Code supprime / nettoye :** ~1500 lignes (Blade + PHP + JS + CSS + tests) reparties sur 19 fichiers. Aucune fonctionnalite metier perdue : tout etait deja redirige ou inutilise.

**Tests :** 369 passent (2406 assertions), 0 failed, 3 skipped (5 tests legacy obsoletes supprimes au passage, 2 tests adaptes au nouveau perimetre).

---

## 2026-05-29 — Modules sidebar : 8 placeholders recables vers les pages fonctionnelles existantes

### Contexte
Retour utilisateur : *"Rends fonctionnels tous les modules qui ne le sont pas actuellement dans l'appli."*

Audit des 8 modules qui menaient au placeholder "Ecran en cours de raccordement" (catch-all `WorkspaceController::module()`). Plutot que de creer 8 controleurs + 8 vues from-scratch (1-2 jours de travail), j'ai recable chaque module vers une page DEJA fonctionnelle en passant les bons filtres URL. Resultat : modules fonctionnels immediatement avec des donnees reelles.

### Modules recables
| Module | Roles concernes | AVANT (placeholder) | APRES (fonctionnel) |
|---|---|---|---|
| `controle` | SCIQ, Planification | `/workspace/controle` | `/workspace/actions?vue=pilotage&statut_validation=soumise_chef` |
| `corrections` | Agent | `/workspace/corrections` | `/workspace/actions?vue=mes_actions&statut_validation=demande_correction` |
| `agents` | Chef de service, UCAS | `/workspace/agents` | `/workspace/referentiel/utilisateurs` |
| `services_agents` | Directeur, DAF | `/workspace/agents` | `/workspace/referentiel/utilisateurs` |
| `synthese_agence` | DG, DGA, Cabinet | `/workspace/synthese-agence` | `/workspace/reporting` |
| `arbitrages` | DG | `/workspace/arbitrages` | `/workspace/demandes-deverrouillage` |
| `financements_critiques` | DG | `/workspace/financements-critiques` | `/workspace/daf/financements-actions` |
| `rapports_consolides` | DG, DGA, Cabinet | `/workspace/rapports-consolides` | `/workspace/reporting` |
| `supervision` | DGA, Cabinet | `/workspace/supervision` | `/workspace/reporting` |

### Fichier modifie
[app/Services/UserWorkspaceService.php](../app/Services/UserWorkspaceService.php) — methode `specRoleModules` : URLs des modules placeholder remplacees par les URLs fonctionnelles. Commentaire global en tete expliquant la regle de recablage.

### Resultat par profil

**DG (ingrid@anbg.ga) — 9 modules tous fonctionnels :**
- Pilotage → /dashboard ✓
- Synthèse agence → /workspace/reporting ✓ (etait placeholder)
- Arbitrages → /workspace/demandes-deverrouillage ✓ (etait placeholder)
- Déverrouillages → /workspace/demandes-deverrouillage ✓
- Financements critiques → /workspace/daf/financements-actions ✓ (etait placeholder)
- Rapports consolidés → /workspace/reporting ✓ (etait placeholder)
- Alertes → /workspace/alertes ✓
- Mes tâches → /workspace/mes-taches ✓
- Notifications → /workspace/notifications ✓

**Chef de service (marie.simba@anbg.ga) — 8 modules tous fonctionnels :**
- Agents / RMO → /workspace/referentiel/utilisateurs ✓ (etait placeholder)
- (tous les autres deja fonctionnels)

**SCIQ — 10 modules tous fonctionnels :**
- Contrôle → /workspace/actions?vue=pilotage&statut_validation=soumise_chef ✓ (etait placeholder)

### Pourquoi ce design (recablage vs reimplementation)
Les pages cibles existent deja et :
1. **Periment filtre** par scope utilisateur (chef voit son service, DG voit tout, etc.) — pas de duplication.
2. **Donnees reelles** : reporting consolide, demandes de deverrouillage, financements DAF, referentiel utilisateurs sont deja peuples.
3. **Filtres URL natifs** : `?vue=pilotage`, `?statut_validation=soumise_chef`, etc. — les controleurs cibles savent deja les gerer.
4. **Maintenabilite** : 1 evolution sur la page reporting profite a la fois a `synthese_agence`, `rapports_consolides`, `supervision`.

### Note sur le placeholder
Le `WorkspaceController::module()` + `module-placeholder.blade.php` restent en place comme filet de securite. Si un nouveau module est ajoute dans la spec sans implementation, il affichera toujours le placeholder au lieu d'une 404.

### Validation
- **374 tests passent** (2442 assertions), 0 failed ✓
- Caches purges (view, route, config) — necessaire pour que les changements de spec roles soient pris en compte

---

## 2026-05-29 — UI : retrait du prefixe "#" devant les ID dans toutes les tables / dropdowns metier

### Contexte
Retour utilisateur : *"Pourquoi il y a toujours le caractere # devant les chiffres ?"*

Le prefixe `#` etait utilise dans plusieurs endroits pour indiquer "numero" devant l'ID (ex: `#7 - PAS 2026-2028`). L'utilisateur trouve ca confus et inutile, surtout depuis l'ajout des codes explicites (PAS-2026-2028, PAO-DG-2026, etc.) dans les tables.

### Fichiers modifies
- [resources/views/workspace/pas/index.blade.php](../resources/views/workspace/pas/index.blade.php) ligne 114 — colonne ID sans `#`
- [resources/views/workspace/pao/index.blade.php](../resources/views/workspace/pao/index.blade.php) ligne 55, 153 — colonne ID + filter dropdown PAS sans `#`
- [resources/views/workspace/pta/index.blade.php](../resources/views/workspace/pta/index.blade.php) ligne 62, 159 — colonne ID + filter dropdown PAO sans `#`
- [resources/views/workspace/actions/index.blade.php](../resources/views/workspace/actions/index.blade.php) ligne 212 — filter dropdown PTA sans `#`
- [resources/views/workspace/actions/financements-daf.blade.php](../resources/views/workspace/actions/financements-daf.blade.php) ligne 34 — filter dropdown PTA sans `#`
- [resources/views/workspace/actions/form.blade.php](../resources/views/workspace/actions/form.blade.php) lignes 142, 213, 236, 269 — KPI label PAO + dropdowns OO / Action / PTA sans `#`
- [resources/views/workspace/pta/form.blade.php](../resources/views/workspace/pta/form.blade.php) ligne 160 — dropdown OO sans `#`
- [resources/views/workspace/actions/suivi.blade.php](../resources/views/workspace/actions/suivi.blade.php) lignes 176, 413 — eyebrow "Action #ID" → "Action {code}" + champ ID sans `#`

### Changements types
```blade
{{-- AVANT --}}
<td class="font-mono">#{{ $row->id }}</td>
<option>#{{ $pao->id }} - {{ $pao->titre }}</option>
<span class="showcase-eyebrow">Action #{{ $action->id }}</span>

{{-- APRES --}}
<td class="font-mono">{{ $row->id }}</td>
<option>{{ $pao->titre }}</option>
<span class="showcase-eyebrow">Action {{ $action->code ?? $action->id }}</span>
```

### Ce qui n'a PAS ete touche
- Vues admin / debug (super_admin, audit, snapshots, retention) qui exposent les IDs systeme : `#` conserve car ces vues s'adressent a des utilisateurs techniques qui comprennent l'identifiant DB.
- Anchors HTML `#{{ $anchor }}` (selector CSS/JS) — pas un prefixe d'affichage.

### Validation
- Cache de vues purge
- 174 tests PAS/PAO/PTA/Action passent (1097 assertions)

---

## 2026-05-29 — PTA importes en statut BROUILLON + transition automatique apres parametrage

### Contexte
Retour utilisateur : *"Normalement le PTA ne doit pas etre deja enregistre lorsqu'il est importe car ses actions doivent etre parametrees et enregistrees une par une par les profils competents."*

Avant : les PTA crees lors de l'import Excel naissaient en statut `EN_COURS` (deja consideres comme "enregistres" / actifs), alors que leurs actions etaient toutes en `statut_parametrage = 'a_parametrer'`. Cette incoherence faisait apparaitre les PTA comme valides dans les listes / tableaux de bord avant meme que le chef de service ait fait son travail de parametrage.

### Nouvelle regle metier
1. **Apres import** : le PTA nait en statut `BROUILLON`. Il est visible dans la liste des PTA avec un badge gris neutre. Il n'est PAS considere comme "enregistre".
2. **Pendant le parametrage** : le chef de service ouvre chaque action, complete les parametres manquants (responsables, livrables, cibles, etc.), enregistre via le bouton "Enregistrer" inline. Quand une action est enregistree, son `statut_parametrage` passe de `a_parametrer` a `parametre`.
3. **Transition automatique** : a chaque sauvegarde d'action, le systeme verifie si toutes les actions du PTA sont parametrees. Si oui, le PTA bascule automatiquement de `BROUILLON` a `EN_COURS` — il est alors officiellement "enregistre".
4. **Cycle ulterieur** : EN_COURS → CLOTURE (cloture explicite par le chef) → ARCHIVE (archivage explicite).

### Fichiers modifies
- [app/Models/Pta.php](../app/Models/Pta.php) ligne 16 — ajout `STATUS_BROUILLON = 'brouillon'`
- [app/Services/Imports/PlanningExcelImportService.php](../app/Services/Imports/PlanningExcelImportService.php) ligne 665 — utilise `STATUS_BROUILLON` au lieu de `STATUS_EN_COURS` a la creation
- [app/Http/Controllers/Web/PtaWebController.php](../app/Http/Controllers/Web/PtaWebController.php) — nouvelle methode privee `maybePromoteBrouillonToEnCours(Pta $pta)` appelee a la fin de `syncPtaActions` ; transition declenchee a chaque save d'action
- [resources/views/workspace/pta/index.blade.php](../resources/views/workspace/pta/index.blade.php) — badge `anbg-badge-neutral` (gris) pour BROUILLON, distinct du badge `anbg-badge-warning` (jaune) pour EN_COURS

### Changement cle
```php
// AVANT (PlanningExcelImportService)
$pta = Pta::query()->firstOrCreate(
    ['pao_id' => $pao->id, 'service_id' => $service->id],
    [
        'titre' => 'PTA - '.($service->code ?: $service->libelle),
        'statut' => Pta::STATUS_EN_COURS,  // ← deja "enregistre"
    ]
);

// APRES
$pta = Pta::query()->firstOrCreate(
    ['pao_id' => $pao->id, 'service_id' => $service->id],
    [
        'titre' => 'PTA - '.($service->code ?: $service->libelle),
        // BROUILLON tant que les actions ne sont pas parametrees une par une.
        'statut' => Pta::STATUS_BROUILLON,
    ]
);
```

```php
// Transition automatique (PtaWebController)
private function maybePromoteBrouillonToEnCours(Pta $pta): void
{
    if ($pta->statut !== Pta::STATUS_BROUILLON) return;

    $pendingActionsCount = Action::query()
        ->where('pta_id', $pta->id)
        ->where('statut_parametrage', 'a_parametrer')
        ->count();

    if ($pendingActionsCount > 0) return;

    // Toutes les actions sont parametrees → le PTA est officiellement enregistre.
    $pta->forceFill(['statut' => Pta::STATUS_EN_COURS])->save();
}
```

### Verification manuelle (tinker)
```
PTA cree avec STATUS_BROUILLON ✓
Actions en a_parametrer = 2 → maybePromote → reste BROUILLON ✓
Marque toutes en parametre → maybePromote → passe EN_COURS ✓
```

### Tests
- **374 tests passent** (2442 assertions), 0 failed, 3 skipped ✓
- Aucun test n'assertait specifiquement le statut PTA post-import (le test `PlanningExcelImportServiceTest::valid_import_creates_grouped_planning_tree` reste vert)

### Note UI
- Le badge BROUILLON utilise `anbg-badge-neutral` (gris), distinct d'EN_COURS (`anbg-badge-warning`, jaune).
- `canClose = false` pour les PTA en brouillon : on ne peut pas cloturer un PTA dont les actions ne sont pas parametrees.
- Les PTA existants en EN_COURS ne sont pas affectes (seul l'import et la creation manuelle generent BROUILLON).

---

## 2026-05-29 — Bypass des verrous etendu aux roles operationnels habilites

### Contexte
Apres le fix SA/DG, l'utilisateur a constate via screenshot que les chefs de service, directeurs, planification et SCIQ continuaient de recevoir "Donnees invalides. Champs : actions" en tentant d'enregistrer une action deja parametree.

Sa formulation : *"C'est eux son habilite de le faire"* — les chefs de service / directeur / planification / SCIQ sont les operateurs LEGITIMES de leur perimetre. Ils ne devraient pas avoir besoin de passer par le workflow de demande de deverrouillage du DG pour modifier leurs propres elements.

### Diagnostic
Le precedent fix (2026-05-28) ne couvrait que SA et DG. Quand le chef de service sauvait une action, la methode `syncPtaActions` declenchait `lockAfterSave`, verrouillant l'action. Au save suivant, `ensureUnlocked` bloquait le meme chef de service de son propre travail.

### Solution
Extension du bypass `ensureUnlocked` selon le PERIMETRE de l'utilisateur :

1. **SA + DG** : pilotage complet (deja en place)
2. **Operateurs globaux** : PLANIFICATION, SCIQ, SCIQ_SUIVI_GLOBAL, CHEF_UNITE_SCIQ, ADMIN_FONCTIONNEL → bypass partout
3. **Direction** : bypass uniquement sur SA direction (PAO, PTA et actions de sa direction)
4. **Chef de service / Chef d'unite (SERVICE, CHEF_UNITE, CHEF_UNITE_UCAS, CHEF_UNITE_DGA, CHEF_UNITE_CABINET, UCAS)** : bypass uniquement sur SON service (PTA et actions de son service)
5. **Agents et autres roles** : verrou applique normalement, workflow de demande de deverrouillage conserve

Cette extension reflete la realite operationnelle ANBG : ce sont ces roles qui parametrent, mettent a jour et suivent les actions au quotidien.

### Fichier modifie
[app/Services/PlanningModificationLockService.php](../app/Services/PlanningModificationLockService.php) — methode `ensureUnlocked` deleguee a `canBypassLock(User $actor, Model $target)` qui applique la matrice de bypass scopee.

### Changement cle
```php
// AVANT (fix 2026-05-28) — SA + DG seulement
public function ensureUnlocked(Model $target, ?User $actor = null): ?string
{
    if ($actor !== null && ($actor->isSuperAdmin() || $actor->hasRole(User::ROLE_DG))) {
        return null;
    }
    return $this->isLocked($target) ? $this->lockedMessage($target) : null;
}

// APRES — bypass scope-aware pour 4 categories de roles
public function ensureUnlocked(Model $target, ?User $actor = null): ?string
{
    if ($actor !== null && $this->canBypassLock($actor, $target)) {
        return null;
    }
    return $this->isLocked($target) ? $this->lockedMessage($target) : null;
}

private function canBypassLock(User $actor, Model $target): bool
{
    // 1. Pilotage complet : SA + DG.
    if ($actor->isSuperAdmin() || $actor->hasRole(User::ROLE_DG)) return true;

    // 2. Operateurs globaux : ecriture globale.
    if ($actor->hasRole(User::ROLE_PLANIFICATION, User::ROLE_SCIQ, ...)) return true;

    [$directionId, $serviceId] = $this->targetScope($target);

    // 3. Direction in-scope.
    if ($actor->hasRole(User::ROLE_DIRECTION) && $actor->direction_id !== null) {
        return $directionId === null || (int) $actor->direction_id === (int) $directionId;
    }

    // 4. Chef de service / chef d'unite in-scope.
    if ($actor->hasRole(User::ROLE_SERVICE, User::ROLE_CHEF_UNITE, ...) && $actor->service_id !== null) {
        return $serviceId !== null
            && (int) $actor->service_id === (int) $serviceId
            && (int) $actor->direction_id === (int) $directionId;
    }

    return false;
}
```

### Verification manuelle (tinker, Action #525 dir=4 srv=13)
```
Super Admin      → ✓ PASSE
DG               → ✓ PASSE
Planification    → ✓ PASSE (global)
SCIQ             → ✓ PASSE (global)
Admin fonctionnel→ ✓ PASSE (global)
Directeur DSIC (dir=4)         → ✓ PASSE (in-scope)
Directeur DS (dir=3)           → BLOQUE (out-of-scope)
Chef service SIRS (dir=4 srv=13)→ ✓ PASSE (in-scope)
Chef service ENB (dir=3 srv=9) → BLOQUE (out-of-scope)
Agent                          → BLOQUE (workflow demande deverrouillage)
```

### Tests mis a jour
[tests/Feature/PlanningModificationLockWorkflowTest.php](../tests/Feature/PlanningModificationLockWorkflowTest.php) — anciens tests "locked pta update requires dg unlock" et "locked action quick status requires dg unlock" renommes/reecrits en "chef service in scope bypasses lock". Ils valident desormais que le chef de service IN-SCOPE peut ecrire directement sans demande, conformement a la regle metier.

### Validation
- **374 tests passent** (2442 assertions), 0 failed, 3 skipped ✓
- Caches Laravel purges

---

## 2026-05-29 — SA/DG bypassent les verrous de modification (ecriture inline + suppression PAS)

### Contexte (2 bugs reportes)
1. **"Je n'arrive toujours pas a enregistrer une action que j'ai deja renseignee, ca me donne un message rouge"** : le bouton "Enregistrer" inline sur le formulaire PTA retourne 422 avec message d'erreur sur les actions deja existantes (qui ont ete verrouillees lors de leur premier enregistrement).
2. **"Je n'arrive toujours pas a supprimer les PAS avec le compte super admin ou du DG"** : la suppression cascade etait fonctionnelle cote service mais le PAS update et autres operations etaient bloques par `ensureUnlocked` quand le PAS etait verrouille.

### Diagnostic
Le service `PlanningModificationLockService::ensureUnlocked()` ne prenait pas en compte le role de l'utilisateur — peu importe que ce soit SA/DG ou un agent, le verrou bloquait. Or la regle metier ANBG (2026-05-28) prevoit que le Super Admin et le DG ont un pilotage complet et peuvent ecrire/supprimer sans restriction.

### Solution architecturale
Modification du signature `ensureUnlocked(Model $target, ?User $actor = null)` au niveau du service de verrou. Quand `$actor` est SA ou DG, retourne `null` (= OK pour ecrire) sans verifier le verrou.

### Fichiers modifies
- [app/Services/PlanningModificationLockService.php](../app/Services/PlanningModificationLockService.php) — methode `ensureUnlocked` etendue avec parametre `$actor` optionnel
- [app/Http/Controllers/Web/PasWebController.php](../app/Http/Controllers/Web/PasWebController.php) ligne 217 — passe `$user`
- [app/Http/Controllers/Web/PtaWebController.php](../app/Http/Controllers/Web/PtaWebController.php) lignes 231, 332, 468, 752-758 — passe `$user`/`$actor`
- [app/Http/Controllers/Web/ActionWebController.php](../app/Http/Controllers/Web/ActionWebController.php) lignes 153, 424, 585, 740 — passe `$user`
- [app/Http/Controllers/Api/PasController.php](../app/Http/Controllers/Api/PasController.php) ligne 169 — passe `$request->user()`
- [app/Http/Controllers/Api/PtaController.php](../app/Http/Controllers/Api/PtaController.php) lignes 115, 204 — idem
- [app/Http/Controllers/Api/ActionController.php](../app/Http/Controllers/Api/ActionController.php) lignes 267, 371 — idem
- [app/Services/Imports/PlanningExcelImportService.php](../app/Services/Imports/PlanningExcelImportService.php) ligne 688 — passe `$user`
- [tests/Feature/PlanningModificationLockWorkflowTest.php](../tests/Feature/PlanningModificationLockWorkflowTest.php) — test `test_locked_pas_update_requires_dg_unlock_and_relocks_after_save` renomme et reecrit en `test_super_admin_bypasses_lock_on_pas_update_and_relocks_after_save` (le SA bypass desormais le workflow de demande)

### Changement cle

**Service de verrou (PlanningModificationLockService) :**
```php
// AVANT
public function ensureUnlocked(Model $target): ?string
{
    return $this->isLocked($target) ? $this->lockedMessage($target) : null;
}

// APRES
public function ensureUnlocked(Model $target, ?User $actor = null): ?string
{
    // SA et DG peuvent ecrire meme sur un element verrouille (pilotage complet).
    if ($actor !== null && ($actor->isSuperAdmin() || $actor->hasRole(User::ROLE_DG))) {
        return null;
    }

    return $this->isLocked($target) ? $this->lockedMessage($target) : null;
}
```

**Exemple de call site :**
```php
// AVANT
if ($message = $lockService->ensureUnlocked($action)) {
    throw ValidationException::withMessages(['actions' => $message]);
}

// APRES
if ($message = $lockService->ensureUnlocked($action, $actor)) {
    throw ValidationException::withMessages(['actions' => $message]);
}
```

### Verification manuelle (tinker)
```
Action #525 verrouillee = OUI
  superadmin@anbg.ga (super_admin) ensureUnlocked = ✓ PASSE
  ingrid@anbg.ga (dg)              ensureUnlocked = ✓ PASSE
  marie.simba@anbg.ga (service)    ensureUnlocked = BLOQUE (workflow demande deverrouillage requis)

PAS destroy SA : Status 302 → PAS soft-deleted ✓
```

### Validation
- **374 tests passent** (2454 assertions), 0 failed, 3 skipped ✓
- Caches Laravel purges (`view:clear`, `route:clear`, `config:clear`, `cache:clear`) — important pour eviter qu'une session navigateur garde l'ancienne logique en memoire compilee

### Note pour l'utilisateur
Si le bug persiste apres ce fix : **vider le cache navigateur (Ctrl+Shift+R)** ou tester en navigation privee. Les assets JS / vues compilees ont change.

---

---

## 2026-05-28 — Suppression des graphiques KPI conformite dans toute l'app

### Contexte
Retour utilisateur : "JE CONSTATE QUE DANS LA PARTI GRAPHIQUE IL Y A TOUJOUR DES GRAPHIQUE CONCERNANT LES KPI CONFORMITE ET DANS TOUTE L'APPLI AUSSI IL FAUX LES SUPRIMER". Le KPI "conformite" avait deja ete supprime au niveau base de donnees (migration `2026_05_28_120000_drop_chef_quality_note_and_conformite_kpi.php`) mais des traces visuelles subsistaient dans les dashboards, reports et alertes.

### Elements visuels supprimes
- **Jauge "Conformite"** dans le panneau Charts du dashboard analytics (gauge grid passe de 3 → 2 metriques)
- **Colonne "Conformite"** dans le tableau des actions prioritaires (dashboard)
- **Colonne "Conformite"** dans le tableau des alertes actives (dashboard)
- **Ligne "Conf."** dans la mini-table indicateurs du dashboard
- **Courbe "Conformite"** dans le graphique KPI mensuel (dashboard-render.js)
- **Jauge "Conformite"** dans `mountKpiGaugeSet` (dashboard-render.js)
- **Badge "Conformite"** dans la liste des alertes monitoring
- **Colonne "Conformite (%)"** dans le tableau reporting service (monitoring)
- **Colonne "Conformite (%)"** dans le reporting PDF KPI
- **Chip "Conformite"** dans les notifications/alertes (admin-shell.js)

### Fichiers modifies
- [resources/views/partials/dashboard-analytics/_panel-charts.blade.php](../resources/views/partials/dashboard-analytics/_panel-charts.blade.php) — boucle gauges sans `conformite`
- [resources/views/partials/dashboard-analytics/_panel-tables.blade.php](../resources/views/partials/dashboard-analytics/_panel-tables.blade.php) — 2 tableaux nettoyes (actions prioritaires + alertes)
- [resources/views/partials/dashboard-analytics.blade.php](../resources/views/partials/dashboard-analytics.blade.php) — ligne "Conf." retiree
- [resources/views/workspace/monitoring/alertes.blade.php](../resources/views/workspace/monitoring/alertes.blade.php) — boucle metrics sans `kpi_conformite`
- [resources/views/workspace/monitoring/partials/reporting-direction-service-sections.blade.php](../resources/views/workspace/monitoring/partials/reporting-direction-service-sections.blade.php) — colonne retiree, `colspan` ajuste 6 → 5
- [resources/views/workspace/monitoring/reporting-pdf.blade.php](../resources/views/workspace/monitoring/reporting-pdf.blade.php) — colonne retiree, `colspan` ajuste 9 → 8
- [resources/js/dashboard-render.js](../resources/js/dashboard-render.js) — `kpiDatasets` et `definitions` sans `conformite`
- [resources/js/admin-shell.js](../resources/js/admin-shell.js) — `metricChips` sans `Conformite`
- [tests/Feature/WebWorkspaceTest.php](../tests/Feature/WebWorkspaceTest.php) — assertion `Conformité (%)` invertie en `assertStringNotContainsString`

### Note sur les donnees backend
Les services et modeles continuent d'exposer le champ `kpi_conformite` (calcul existant, exports XLSX historiques). Seules les representations VISUELLES (graphiques, jauges, tableaux UI) sont retirees. Cela permet :
- de garder la retro-compatibilite des exports existants
- de ne pas perdre l'historique des valeurs calculees
- de reactiver facilement les graphiques si besoin futur

### Validation
- **374 tests passent** (2461 assertions), 0 failed ✓

---

## 2026-05-28 — Fusion modules "Mes Actions" + "Actions" sous le nom "Action"

### Contexte
Retour utilisateur : "MET LE MODULE MES ACTION ET ACTION ENSEMBLE SOUS LE NOM ACTION". Le menu lateral exposait deux entrees distinctes :
- `mes_actions` "Mes actions" → /workspace/actions?vue=mes_actions
- `execution` "Actions" → /workspace/actions

Cela cree de la confusion : les deux pointent vers le meme controleur (`ActionWebController::index`) qui possede deja des onglets `pilotage` / `mes_actions` (`showDualActionTabs`). Avoir deux entrees sidebar pour le meme ecran fait doublon.

### Nouvelle organisation
- Un seul module sidebar : `execution` avec label "Action" (singulier).
- Les utilisateurs basculent entre "Mes actions" et "Pilotage" via les onglets DEJA presents dans la page (controles par `?vue=mes_actions` ou `?vue=pilotage`).
- Pour les roles qui n'avaient que `mes_actions` (agent, dga_cabinet, auditeur), `execution` est utilise mais pointe vers `?vue=mes_actions` pour pre-filtrer sur "Mes actions".

### Fichiers modifies
- [app/Services/UserWorkspaceService.php](../app/Services/UserWorkspaceService.php) — toutes les occurrences `mes_actions` remplacees par `execution` (label "Action"). Module de premier niveau renomme "Actions"→"Action".
- [tests/Feature/AgentRbacNavigationTest.php](../tests/Feature/AgentRbacNavigationTest.php) — assertion mise a jour : agent voit `execution` au lieu de `mes_actions`.
- [tests/Feature/RolePermissionMatrixTest.php](../tests/Feature/RolePermissionMatrixTest.php) — matrice complete actualisee, 11 roles touches (mes_actions retire de PLANIFICATION, SCIQ, CABINET, DGA_SUPERVISION, AGENT, AUDITEUR).

### Changement type
```php
// AVANT (UserWorkspaceService::specRoleModules)
'agent' => [
    $m('pilotage', 'Dashboard', '/dashboard'),
    $m('mes_actions', 'Mes actions', '/workspace/actions?vue=mes_actions', [...]),
    ...
],
'sciq_planif' => [
    ...
    $m('execution', 'Actions', '/workspace/actions'),
    $m('mes_actions', 'Mes actions', '/workspace/actions?vue=mes_actions'),
    ...
],

// APRES
'agent' => [
    $m('pilotage', 'Dashboard', '/dashboard'),
    $m('execution', 'Action', '/workspace/actions?vue=mes_actions', [...]),
    ...
],
'sciq_planif' => [
    ...
    $m('execution', 'Action', '/workspace/actions'),
    // (mes_actions fusionne avec 'execution' / Action — utiliser les onglets de la page).
    ...
],
```

### Validation
- **374 tests passent** (2461 assertions), 0 failed ✓
- Tests AgentRbacNavigationTest + RolePermissionMatrixTest verts apres mise a jour de la matrice

### Note
Les liens internes (notifications, deeplinks, vieilles URLs partagees) utilisant `/workspace/actions?vue=mes_actions` continuent de fonctionner — seul le module sidebar change de label/code.

---

## 2026-05-28 — Formulaire PTA : actions deroulantes + 3 boutons par action (save AJAX inline)

### Contexte
Retour utilisateur : "Pour chaque formulaire d'action on doit voir en entrant juste le nom de l'action lorsque les formulaires sont fermes et lorsque on deroule on voit tout le formulaire de l'action selectionnee. Et je veux que chaque formulaire de chaque action a un bouton Enregistrer, Modifier, Supprimer et ces 3 boutons doivent etre operationnels."

Avant : tous les blocs d'action etaient ouverts en permanence, l'enregistrement se faisait en bloc via "Enregistrer le PTA" en bas du formulaire.

### Nouveau comportement
- Chaque bloc d'action utilise l'element HTML5 `<details>` : ferme par defaut pour les actions deja persistees, ouvert pour les nouvelles ou en cas d'erreurs de validation.
- Header cliquable (`<summary>`) qui affiche uniquement le nom (libelle) de l'action quand le bloc est ferme.
- 3 boutons dans le header de chaque action :
  - **Enregistrer** (vert) : sauvegarde AJAX inline de cette action seule (POST `workspace.pta.actions.upsert-inline`).
  - **Modifier** (orange, visible quand ferme) : ouvre l'accordeon, focus le 1er champ, scroll vers le bloc.
  - **Supprimer** (rouge) : DELETE AJAX vers `workspace.actions.destroy` pour les actions persistees (avec confirmation + motif), simple retrait DOM pour les nouvelles.

L'ancien bouton global "Enregistrer le PTA" en bas reste pour les sauvegardes en bloc lors de la creation initiale.

### Fichiers modifies
- [resources/views/workspace/pta/partials/action-form-block.blade.php](../resources/views/workspace/pta/partials/action-form-block.blade.php) — `<section>` → `<details>`, `<div class="heading">` → `<summary>`, ajout des 3 boutons.
- [resources/views/workspace/pta/form.blade.php](../resources/views/workspace/pta/form.blade.php) — JS : fonctions `collectActionPayload`, `flashActionMessage`, handlers `data-save-action` / `data-edit-action` / `data-remove-action`.
- [app/Http/Controllers/Web/PtaWebController.php](../app/Http/Controllers/Web/PtaWebController.php) — nouvelle methode `upsertActionInline(Request, Pta)` qui valide une action seule et delegue a `syncPtaActions`.
- [routes/web.php](../routes/web.php) — nouvelle route `POST workspace/pta/{pta}/actions/upsert-inline`.

### Architecture cle

**Cote PHP (PtaWebController) :**
```php
public function upsertActionInline(Request $request, Pta $pta): JsonResponse
{
    // 1. Auth + denyUnlessManagePta (perimetre)
    // 2. ensureUnlocked (PTA non verrouille)
    // 3. validate (memes regles que actions.* mais sans prefixe)
    // 4. syncPtaActions($pta, $oo, [$validated], $user) // ← reuse de l'existant
    // 5. return JSON { ok, message, action:{id,code,libelle} }
}
```

**Cote JS :**
```js
function collectActionPayload(block) {
    // Parcourt tous les inputs/select/textarea du bloc dont name commence par actions[N][...]
    // Construit un objet imbrique { libelle, rmo_ids:[], sous_actions:[{...}], ... }
    // Strip le prefixe actions[N] pour matcher la validation du handler upsert.
}

// Click sur "Enregistrer" :
fetch(upsertUrl, { method: 'POST', body: JSON.stringify(payload), headers: { X-CSRF-TOKEN, ... } })
  .then(... → met a jour data-action-id, id de section, summary libelle, flash success)

// Click sur "Modifier" :
block.open = true; firstInput.focus(); block.scrollIntoView();

// Click sur "Supprimer" (persistee) :
fetch(actions.destroy, { method: POST + _method=DELETE, body: motif }) → remove() block

// Click sur "Supprimer" (non persistee) :
block.remove(); refreshActionIndexes();
```

### Validation
- Route enregistree : `POST workspace/pta/{pta}/actions/upsert-inline` ✓
- 133 tests `Pta|Action` passent, 1 skipped (sans rapport) ✓
- Test manuel requis dans le navigateur (impossible automatiquement) : ouvrir un PTA en edition, verifier que chaque action est fermee par defaut, que les 3 boutons fonctionnent.

### Limitations connues
- L'upsert inline n'est disponible qu'en mode **edition** du PTA (un id de PTA est necessaire). En **creation**, le bouton "Enregistrer" affiche un message redirigeant vers le bouton global en bas.
- Pas de gestion des upload de fichiers (justificatif_financement) en mode inline AJAX. Si l'utilisateur joint un fichier, il doit utiliser le submit global.

---

## 2026-05-28 — Fuite de perimetre PAS corrigee : filtrage par perimetre utilisateur

### Contexte
Retour utilisateur : "LE PERIMETRE DES UTILISATEUR N'EST PAS RESPECTER". Diagnostic complet sur tous les modules :
- **PAS index** : aucun filtre de perimetre (tout utilisateur avec `planning.read` voyait TOUS les PAS) ❌
- **PAO index** : filtre OK ✓
- **PTA index** : filtre OK ✓
- **Action index** : filtre OK ✓
- **PTA edit (URL directe)** : protection OK ✓ (403 sur PTA hors perimetre)
- **PAO edit (URL directe)** : protection OK ✓
- **AJAX dependent select PTA** : filtre OK ✓ (retourne uniquement les PTA du service)

La seule fuite confirmee etait sur le PAS : `scopePasByUser` se contentait de verifier la permission `planning.read` sans filtrer la liste par direction/service.

### Nouvelle regle de perimetre PAS
Un PAS est visible si :
1. l'utilisateur a `canReadAllPlanning` (super_admin, DG, planification, SCIQ, cabinet, admin_fonctionnel) → tous les PAS
2. l'utilisateur est ROLE_DIRECTION → PAS qui contient au moins un PAO de sa direction
3. l'utilisateur a un perimetre service (ROLE_SERVICE / chef d'unite / UCAS / etc.) → PAS qui contient :
   - un PAO directement rattache a son service (PAO.service_id = user_service_id), OU
   - un PAO de sa direction avec un PTA de son service, OU
   - un PAO de sa direction avec un objectif operationnel de son service
4. l'utilisateur a une delegation → meme logique sur les directions/services delegues
5. aucun perimetre actif = aucun PAS visible

### Fichier modifie
[app/Http/Controllers/Api/Concerns/AuthorizesPlanningScope.php](../app/Http/Controllers/Api/Concerns/AuthorizesPlanningScope.php) — methode `scopePasByUser` etendue, methode `canReadPas` reutilise le scope.

### Changement cle
```php
// AVANT — aucun filtre
protected function scopePasByUser(Builder|Relation $query, User $user): void
{
    if ($this->canReadPlanningScope($user)) {
        return;  // ← lecteurs partiels voyaient TOUT
    }
    $query->whereRaw('1 = 0');
}

// APRES — filtrage par perimetre
protected function scopePasByUser(Builder|Relation $query, User $user): void
{
    if (! $this->canReadPlanningScope($user)) { $query->whereRaw('1 = 0'); return; }
    if ($this->canReadAllPlanning($user)) return;  // global readers ok

    // Construction directionIds + serviceScopes (delegations + role)
    // ...
    $query->where(function (Builder $pasQuery) use (...): void {
        if (!empty($directionIds)) {
            $pasQuery->orWhereHas('paos', fn ($q) => $q->whereIn('direction_id', $directionIds));
        }
        foreach ($uniqueServiceScopes as $scope) {
            $pasQuery->orWhereHas('paos', function ($paoQuery) use ($scope) {
                $paoQuery->where('direction_id', $scope['direction_id'])
                    ->where(fn ($inner) => $inner
                        ->where('service_id', $scope['service_id'])
                        ->orWhereHas('ptas', fn ($q) => $q->where('service_id', $scope['service_id']))
                        ->orWhereHas('objectifsOperationnels', fn ($q) => $q->where('service_id', $scope['service_id']))
                    );
            });
        }
    });
}
```

### Tests mis a jour
- [tests/Unit/PasPolicyTest.php](../tests/Unit/PasPolicyTest.php) — `other_direction_user` et `other_service_user` doivent maintenant assertFalse sur `view()` du PAS hors perimetre

### Verification manuelle (tinker)
| Profil | PAS visibles AVANT | PAS visibles APRES |
|---|---|---|
| Chef ENB (srv 9) | 2 / 2 | **1 / 2** ✓ |
| Directeur DAF (dir 5) | 2 / 2 | **1 / 2** ✓ |
| DG | 2 / 2 | 2 / 2 (inchange) |
| Super Admin | 2 / 2 | 2 / 2 (inchange) |

### Validation
- **374 tests passent** (2461 assertions), 0 failed ✓

---

## 2026-05-28 — DG en pilotage complet + suppression directe en cascade pour SA/DG

### Contexte
Retour utilisateur : "LE SUPER ADMINISTRATEUR ET LA DG N'ARRIVENT PAS A SUPRIME DES DONNEES COMME LE PAS". Diagnostic :
- Le role DG etait en **lecture seule** (heritage commit "A06"), ne pouvait creer/modifier/supprimer aucune entite de planification.
- Le Super Admin etait bloque sur le workflow de demande de suppression des qu'1 seul PAO/PTA/Action etait lie (`hasBlockingImpact`).
- `deleteBusinessTarget` ne faisait pas de cascade reelle (supprimait uniquement les axes, laissait PAOs/PTAs/Actions orphelins).

### Nouvelle regle metier
- **DG** : pilotage complet (`planning.write.global` + `planning.strategic.manage` + `scope.global.write`) — meme niveau d'autorite que Super Admin sur les entites planification.
- **Super Admin et DG** : suppression DIRECTE en cascade (PAOs + PTAs + OOs + Actions + Axes + Objectifs strategiques + sous-actions + semaines). Plus de workflow de demande pour ces deux roles.
- **Autres roles** (service, direction, etc.) : workflow de demande de suppression inchange.

### Fichiers modifies
- [app/Services/RolePermissionSettings.php](../app/Services/RolePermissionSettings.php) lignes 218-235 — permissions DG enrichies
- [app/Services/DeletionRequestService.php](../app/Services/DeletionRequestService.php) — `deleteBusinessTarget` reecrit avec 4 methodes `cascadeDelete*` (PAS / PAO / PTA / Action) dans une transaction
- [app/Http/Controllers/Web/PasWebController.php](../app/Http/Controllers/Web/PasWebController.php) `destroy()` — bypass workflow pour SA + DG, appel `deleteBusinessTarget`
- [app/Http/Controllers/Web/PaoWebController.php](../app/Http/Controllers/Web/PaoWebController.php) `destroy()` — idem
- [app/Http/Controllers/Web/PtaWebController.php](../app/Http/Controllers/Web/PtaWebController.php) `destroy()` — idem
- [app/Http/Controllers/Web/ActionWebController.php](../app/Http/Controllers/Web/ActionWebController.php) `destroy()` — idem + nettoyage justificatifs preservé
- [database/migrations/2026_05_28_140000_grant_dg_full_planning_permissions.php](../database/migrations/2026_05_28_140000_grant_dg_full_planning_permissions.php) — migration alignant la table `platform_settings` (override DB) sur le nouveau matrix de perms

### Changements cles

**Permissions DG (avant/apres) :**
```php
// AVANT (lecture seule)
User::ROLE_DG => [
    'scope.global.read',
    'planning.read',
    'reporting.read',
    'alerts.read',
    'referentiel.read',
    'audit.read',
    'messagerie.read',
],

// APRES (pilotage complet)
User::ROLE_DG => [
    'scope.global.read',
    'scope.global.write',
    'planning.read',
    'planning.write.global',
    'planning.strategic.manage',
    'reporting.read',
    'alerts.read',
    'referentiel.read',
    'audit.read',
    'messagerie.read',
],
```

**Logique destroy (PAS / PAO / PTA / Action) :**
```php
// AVANT
if (! $user->isSuperAdmin() || $deletionRequests->hasBlockingImpact($pas)) {
    // → demande de suppression au lieu de supprimer
}
$pas->delete();  // <- soft delete sans cascade

// APRES
$canDeleteDirectly = $user->isSuperAdmin() || $user->hasRole(User::ROLE_DG);
if (! $canDeleteDirectly) {
    // → demande de suppression (chefs de service, direction, etc.)
}
$deletionRequests->deleteBusinessTarget($pas);  // <- cascade complete via service
```

**Cascade dans DeletionRequestService :**
```php
// AVANT : seulement axes
public function deleteBusinessTarget(Model $target): void
{
    if ($target instanceof Pas) {
        $target->axes()->with('objectifs')->get()->each->delete();
    }
    $target->delete();
}

// APRES : cascade complete, transactionnelle, par type d'entite
public function deleteBusinessTarget(Model $target): void
{
    DB::transaction(function () use ($target): void {
        if ($target instanceof Pas)         $this->cascadeDeletePas($target);
        elseif ($target instanceof Pao)     $this->cascadeDeletePao($target);
        elseif ($target instanceof Pta)     $this->cascadeDeletePta($target);
        elseif ($target instanceof Action)  $this->cascadeDeleteAction($target);
        else $target->delete();
    });
}
// + cascadeDeletePas/Pao/Pta/Action : ordre bottom-up, via models Eloquent
```

### Tests mis a jour
- [tests/Feature/RolePermissionMatrixTest.php](../tests/Feature/RolePermissionMatrixTest.php) — matrice DG alignee sur la nouvelle regle
- [tests/Feature/Phase3AQuickWinsTest.php](../tests/Feature/Phase3AQuickWinsTest.php) — `assertNotContains` → `assertContains` pour `scope.global.write` sur DG
- [tests/Feature/DgReadOnlyRoleTest.php](../tests/Feature/DgReadOnlyRoleTest.php) — test renomme `test_dg_role_has_full_pilotage_permissions`, asserte les nouveaux droits
- [tests/Feature/BusinessDeletionRequestWorkflowTest.php](../tests/Feature/BusinessDeletionRequestWorkflowTest.php) — ancien test "creates request" remplace par `test_super_admin_pao_delete_with_existing_pta_cascades_immediately` + ajout `test_dg_pao_delete_with_existing_pta_cascades_immediately`

### Validation
- **374 tests passent** (2461 assertions), 3 skipped, 0 failed ✓
- Verification manuelle Tinker : DG `hasGlobalWriteAccess` = OUI, `planning.strategic.manage` = OUI, `scope.global.write` = OUI

---

## 2026-05-28 — Tableaux PAS / PAO / PTA plus explicites

### Contexte
Retour utilisateur : les tableaux d'index des modules PAS, PAO et PTA "ne sont plus aussi explicites" que dans les premieres versions. Diagnostic git :
- PAS avait perdu sa colonne ID dediee (deplacee en sous-texte du Titre), "Structure PAS" renomme en "Axes"
- PAO avait perdu sa colonne ID dediee (deplacee en sous-texte du Titre)
- PTA n'avait pas change structurellement (seul renommage "Operations" → "Actions" lors du commit `f1a97cf`)

En plus, certaines colonnes utiles n'existaient dans aucune version : Code explicite (PAS-2026-2028, PAO-DG-2026, PTA-UCAS-2026), Echeance, compteurs detailles par type d'enfant.

### Fichiers modifies
- [resources/views/workspace/pas/index.blade.php](../resources/views/workspace/pas/index.blade.php) — entete et tbody
- [resources/views/workspace/pao/index.blade.php](../resources/views/workspace/pao/index.blade.php) — entete et tbody
- [resources/views/workspace/pta/index.blade.php](../resources/views/workspace/pta/index.blade.php) — entete et tbody

Les controleurs chargeaient deja les relations et withCount necessaires : aucune modification cote PHP requise.

### Nouvelles colonnes par module

**PAS — passe de 6 a 9 colonnes :**
```
AVANT : Titre · Periode · Statut · Axes · PAO · Validateur
APRES : ID · Code · Titre · Periode · Statut · Nb Axes · Nb Obj. Strat. · Nb PAO · Validateur
```
- `ID` : badge mono, ex `#3`
- `Code` : ex `PAS-2026-2028` (genere depuis periode_debut/periode_fin)
- `Nb Obj. Strat.` : badge bleu, calcule via `$row->axes->sum(fn ($axe) => $axe->objectifs->count())`
- Compteurs Axes / PAO desormais dans des colonnes separees avec badges colores

**PAO — passe de 8 a 12 colonnes :**
```
AVANT : PAO (titre+id+ech) · PAS · Obj. strat. · Direction · Annee · Statut · PTA · Validateur
APRES : ID · Code · Titre · PAS · Obj. strat. · Direction · Annee · Echeance · Statut · Nb OO · Nb PTA · Validateur
```
- `Code` : ex `PAO-DG-2026` (colonne `code` du modele)
- `Echeance` : colonne dediee (etait avant en sous-texte du Titre)
- `Nb OO` : badge bleu, via `objectifs_operationnels_count` (deja withCount au controleur)
- `Nb PTA` : badge vert (etait deja la mais sans badge)

**PTA — passe de 8 a 11 colonnes :**
```
AVANT : ID · Titre · PAO · Direction · Service · Statut · Nb actions · Validateur
APRES : ID · Code · Titre · PAO · Objectif operationnel · Direction · Service · Echeance OO · Statut · Nb actions · Validateur
```
- `Code` : ex `PTA-UCAS-2026` (colonne `code` du modele)
- `Objectif operationnel` : libelle de l'OO parent (ex "Structurer le suivi des demandes d'accompagnement des usagers")
- `Echeance OO` : date d'echeance de l'objectif operationnel parent

### Conventions visuelles
- `ID` : `font-mono text-xs text-slate-600`, format `#NNN`
- `Code` : `font-mono text-xs font-semibold text-slate-800`
- Compteurs : badges colores (`anbg-badge-neutral` pour axes, `anbg-badge-info` pour OO/Obj.Strat., `anbg-badge-success` pour PAO/PTA), centres
- Dates / echeances : `whitespace-nowrap text-xs text-slate-700`

### Validation
- **109 tests passent** (957 assertions), 1 skipped (sans rapport)
- Filtres testes : `Pas|Pao|Pta|Web` (tous les tests touchant les vues de planning)
- Aucun test ne checkait le nombre/contenu des colonnes : pas de regression de specs

---

## 2026-05-28 — Modification action : redirection vers le formulaire PTA

### Contexte
Quand un chef de service cliquait "Modifier" sur une action (depuis la liste des actions ou la page de suivi), il atterrissait sur le formulaire standalone `workspace.actions.edit`. Ce formulaire n'expose pas le contexte PTA complet (objectif operationnel, sous-actions, hierarchie des responsables, etc.). Le workflow normal de parametrage exige le formulaire PTA qui contient tous les blocs editables au sein du PTA parent.

### Regle metier
Une action est toujours rattachee a un PTA. Le parametrage d'une action passe par le formulaire PTA, scrollable directement sur la section de l'action visee via une ancre `#action-{id}`.

### Fichiers modifies
- [resources/views/workspace/pta/partials/action-form-block.blade.php](../resources/views/workspace/pta/partials/action-form-block.blade.php) — ajout de l'ancre `id="action-{id}"` sur le bloc d'action
- [resources/views/workspace/actions/index.blade.php](../resources/views/workspace/actions/index.blade.php) ligne 712 — lien "Modifier" pointe vers PTA edit + ancre
- [resources/views/workspace/actions/suivi.blade.php](../resources/views/workspace/actions/suivi.blade.php) ligne 213 — lien "Modifier action" pointe vers PTA edit + ancre
- [app/Http/Controllers/Web/ActionWebController.php](../app/Http/Controllers/Web/ActionWebController.php) methode `edit()` — redirige cote serveur vers le formulaire PTA si l'action a un `pta_id`
- [tests/Feature/ActionWorkflowSecurityTest.php](../tests/Feature/ActionWorkflowSecurityTest.php) — test `test_action_edit_form_does_not_display_creation_link_selector` renomme et reecrit en `test_action_edit_redirects_to_pta_form_with_action_anchor`

### Changements

**Ancre dans le bloc d'action PTA :**
```blade
{{-- AVANT --}}
<section class="pta-action-block ..." data-action-block data-action-index="{{ $index }}">

{{-- APRES --}}
<section @if (! $isTemplate && ! empty($rowData['id'])) id="action-{{ $rowData['id'] }}" @endif
         class="pta-action-block ..." data-action-block data-action-index="{{ $index }}"
         data-action-id="{{ $rowData['id'] ?? '' }}">
```

**Liens UI :**
```blade
{{-- AVANT --}}
href="{{ route('workspace.actions.edit', $row) }}"

{{-- APRES --}}
href="{{ $row->pta_id
    ? route('workspace.pta.edit', $row->pta_id).'#action-'.$row->id
    : route('workspace.actions.edit', $row) }}"
```

**Redirection cote serveur :**
```php
// AVANT
public function edit(Request $request, Action $action): View
{
    // ... charge l'action et affiche workspace.actions.form
}

// APRES
public function edit(Request $request, Action $action): RedirectResponse|View
{
    $user = $request->user();
    if (! $user instanceof User) abort(401);

    if ($action->pta_id) {
        return redirect()->away(
            route('workspace.pta.edit', $action->pta_id).'#action-'.$action->id
        );
    }
    // ... fallback inchange pour les actions orphelines sans pta_id
}
```

### Ce qui n'a PAS ete touche
- La route `workspace.actions.edit` existe toujours (pour compatibilite : URLs en cours, redirections KPI, etc.). Le controleur la traite desormais comme un redirecteur quand l'action a un PTA.
- Le formulaire standalone `workspace.actions.form.blade.php` reste sur le disque mais n'est plus servi pour les actions liees a un PTA.

### Validation
- 132 tests passent (742 assertions), 1 skipped (jeu de donnees de test, sans rapport)
- Test ajuste : `test_action_edit_redirects_to_pta_form_with_action_anchor` verifie la redirection ET que le formulaire PTA cible contient bien la section editable de l'action

---

## 2026-05-28 — Import : PTA et Actions non verrouilles apres import

### Contexte
Regle metier ANBG : apres un import Excel, les PTA et leurs actions doivent rester editables. Les actions sortent de l'import avec `statut_parametrage = 'a_parametrer'` et necessitent un parametrage individuel par le chef de service (responsables, livrables, sous-actions, etc.). C'est le chef de service qui verrouille ensuite chaque element une fois parametre et enregistre.

L'import precedent verrouillait automatiquement :
- Le PTA des sa creation
- Chaque Action a la creation
- Chaque Action a la mise a jour (mode `update_existing`)

Ce comportement bloquait le workflow de parametrage du chef de service (necessite a chaque fois de deverrouiller puis re-verrouiller).

### Fichier modifie
[app/Services/Imports/PlanningExcelImportService.php](../app/Services/Imports/PlanningExcelImportService.php)

### Changements

**Verrou PTA supprime (ligne 668) :**
```php
// AVANT
if ($pta->wasRecentlyCreated) {
    $lockService->lockAfterSave($pta, $user);
}

// APRES
// PTA volontairement NON verrouille apres import : les actions a l'interieur
// ne sont pas encore parametrees (statut_parametrage = 'a_parametrer').
// C'est au chef de service de verrouiller le PTA une fois toutes ses actions
// parametrees et enregistrees individuellement.
```

**Verrou Action supprime (lignes 737 et 748) :**
```php
// AVANT (path update)
$action->fill($payload)->save();
$lockService->lockAfterSave($action->refresh(), $user);

// AVANT (path create)
$action->forceFill([...])->save();
$lockService->lockAfterSave($action->refresh(), $user);

// APRES : appels lockAfterSave supprimes, commentaires explicatifs ajoutes
```

### Ce qui reste verrouille
- **PAS** (Plan d'Action Strategique) : verrouille a la creation, c'est l'enveloppe pluriannuelle definie au niveau strategique
- **Verification `ensureUnlocked` (ligne 687)** : conservee — empeche la re-importation d'une action deja validee par le chef de service en mode `update_existing`

### Validation
- 11 tests `PlanningExcelImportServiceTest` passent (39 assertions, 5.59s)
- Aucun test n'assertait le verrouillage post-import (recherche `lockAfterSave|isLocked|locked_at` vide dans le test) — la regle metier "auto-verrouillage a l'import" n'etait pas couverte par les tests

---

## 2026-05-28 — Correctifs import Excel global PAS-PAO-PTA-Actions

### Contexte
L'import du fichier `modele-import-global-pas-pao-pta-actions-anbg-matricules-agents.xlsx` (72 lignes) échouait avec 3 erreurs successives :
1. `Le classeur ne contient aucune feuille.`
2. `UNIQUE constraint failed: actions.code`
3. `UNIQUE constraint failed: objectifs_operationnels.code`

### Fix 1 — Parser xlsx tolérant aux namespaces préfixés

**Cause :** le parser maison ne gérait que le namespace SpreadsheetML **par défaut** (`<workbook xmlns="...">`), pas les namespaces **préfixés** (`<x:workbook xmlns:x="...">`) produits par OneDrive / ClosedXML / OpenXML SDK .NET.

**Fichier modifié :** [app/Services/Imports/SimpleSpreadsheet.php](../app/Services/Imports/SimpleSpreadsheet.php)

**Changements clés :**
- Constante `SS_NAMESPACE` ajoutée
- Toutes les traversées d'enfants passent par `children(self::SS_NAMESPACE)` (compatible avec namespace par défaut ET préfixé)
- Accès aux attributs via `->attributes()['attr']` au lieu de `['attr']` (nécessaire après `children()`)
- Messages d'erreur précisés (`workbook.xml` manquant vs illisible)

**Avant :**
```php
$workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
$sheetNodes = $workbook?->sheets?->sheet;
// ...
foreach ($xml?->sheetData?->row ?? [] as $row) {
    $rowNumber = (int) ($row['r'] ?? 0);
    foreach ($row->c ?? [] as $cell) {
        $ref = (string) ($cell['r'] ?? '');
```

**Après :**
```php
private const SS_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
// ...
$sheetsContainer = $workbook->children(self::SS_NAMESPACE)->sheets ?? null;
$sheetNodes = $sheetsContainer !== null ? $sheetsContainer->children(self::SS_NAMESPACE)->sheet : null;
// ...
foreach ($rowNodes ?? [] as $row) {
    $rowNumber = (int) ($row->attributes()['r'] ?? 0);
    foreach ($row->children(self::SS_NAMESPACE)->c ?? [] as $cell) {
        $ref = (string) ($cell->attributes()['r'] ?? '');
```

### Fix 2 — Code action incluant l'ordre du PAO

**Cause :** le code action `ACT-{SERVICE}-{YEAR}-{ORDER}` ne contenait pas l'ordre du PAO. Si un service avait plusieurs PAO et que `ordre_action` repartait à 1 pour chacun, les codes collisionnaient (33 doublons détectés sur le fichier d'import).

**Fichiers modifiés :**
- [app/Services/Imports/PlanningImportCodeGenerator.php](../app/Services/Imports/PlanningImportCodeGenerator.php) (méthode `action()`)
- [app/Services/Imports/PlanningExcelImportService.php](../app/Services/Imports/PlanningExcelImportService.php) (ligne 701)

**Avant :**
```php
public function action(Service $service, int $year, int $order): string
{
    return 'ACT-'.$this->token($service->code).'-'.$year.'-'.str_pad($order, 3, '0', STR_PAD_LEFT);
}
// Appel :
'code' => $this->codes->action($service, $year, $actionOrder),
```

**Après :**
```php
public function action(Service $service, int $year, int $objectiveOrder, int $order): string
{
    return 'ACT-'.$this->token($service->code)
        .'-'.$year
        .'-'.str_pad($objectiveOrder, 3, '0', STR_PAD_LEFT)
        .'-'.str_pad($order, 3, '0', STR_PAD_LEFT);
}
// Appel :
'code' => $this->codes->action($service, $year, $operationalOrder, $actionOrder),
```

**Format résultant :** `ACT-UCAS-2026-001-001` = action 1 du PAO 1 du service UCAS pour 2026.

### Fix 3 — Code objectif opérationnel incluant le service

**Cause :** le code OO `PAO-{DIR}-{YEAR}-OO-{ORDER}` ne contenait pas le service. Or les OO sont scopés par (direction, service, PAO) — plusieurs services d'une même direction avec un OO d'ordre 1 chacun produisaient un code identique.

**Fichiers modifiés :**
- [app/Services/Imports/PlanningImportCodeGenerator.php](../app/Services/Imports/PlanningImportCodeGenerator.php) (méthode `operationalObjective()`)
- [app/Services/Imports/PlanningExcelImportService.php](../app/Services/Imports/PlanningExcelImportService.php) (ligne 647)

**Avant :**
```php
public function operationalObjective(Direction $direction, int $year, int $order): string
{
    return $this->pao($direction, $year).'-OO-'.str_pad($order, 3, '0', STR_PAD_LEFT);
}
```

**Après :**
```php
public function operationalObjective(Direction $direction, int $year, Service $service, int $order): string
{
    return $this->pao($direction, $year)
        .'-'.$this->token($service->code)
        .'-OO-'.str_pad($order, 3, '0', STR_PAD_LEFT);
}
```

**Format résultant :** `PAO-DG-2026-UCAS-OO-001` = OO 1 du service UCAS dans le PAO DG 2026.

### Validation
- **Tests :** 11 tests `PlanningExcelImportServiceTest` passent (39 assertions, 4.87s)
- **Fichier réel :** 66 codes d'actions uniques, 22 codes d'OO uniques, 0 collision sur les 72 lignes du fichier utilisateur
- **Rétro-compatibilité parser :** les .xlsx avec namespace par défaut (générés par l'app elle-même) continuent d'être lus correctement

---

## 2026-05-28 — Mise en place du journal de traçabilité

Création de ce fichier `docs/CHANGELOG.md` pour tracer toutes les modifications futures de l'application.

**Convention :**
- Une entrée par session de modifications, datée `YYYY-MM-DD`
- Sections : Contexte, Cause, Fichiers modifiés, Avant/Après, Validation
- Les entrées les plus récentes en haut
