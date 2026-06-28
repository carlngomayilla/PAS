# Analyse fonctionnelle des workflows métier

**Application ANBG — Pilotage PAS / PAO / PTA / Actions**

- Version : 1.0
- Date : 21 mai 2026
- Périmètre : tous les workflows métier de l'application Laravel courante (branche `local/laravel-13-ai-pta`)
- Sources : code (`routes/`, `app/Http/Controllers`, `app/Models`, `app/Services`, `database/migrations`) et documentation (`docs/specifications-fonctionnelles.md`, `docs/analyse-globale-application.md`, `docs/rapport-specifications-fonctionnelles-application-actuelle.md`, `README.md`)

---

## 0. Cadre général

### 0.1 Chaîne fonctionnelle

L'application orchestre une chaîne descendante de planification puis une boucle ascendante d'exécution et de consolidation :

```
PAS  (Plan d'Actions Stratégique — pluriannuel, niveau institution)
 └── Axes stratégiques
      └── Objectifs stratégiques
           └── PAO  (Plan d'Actions Opérationnel — annuel, niveau direction)
                ├── Axes PAO
                ├── Objectifs stratégiques rattachés
                └── Objectifs opérationnels
                     └── PTA  (Plan de Travail Annuel — annuel, niveau service)
                          └── Actions  (unité opérationnelle, niveau agent / service)
                               ├── Sous-actions
                               ├── Semaines (ActionWeek — généré automatiquement)
                               ├── KPI d'action (délai · performance · qualité · risque)
                               ├── Justificatifs (financement / hebdo / final)
                               └── Workflow financement DAF ↔ DG
```

À chaque niveau s'appliquent : un workflow de validation (`brouillon → soumis → validé → verrouillé` avec réouverture motivée), une journalisation d'audit (`journal_audit`), une gestion d'alertes (`AlertCenterService`), et un périmètre de droits dérivé du rôle de l'utilisateur (RBAC + scope direction/service + délégations).

### 0.2 Rôles applicatifs

L'application gère huit profils (sept rôles métier + un rôle technique). Les capacités précises sont paramétrables en ligne via `RolePermissionSettings` (panneau Super-Admin → Rôles & permissions).

| Profil affiché | Identifiant interne | Portée par défaut | Capacités clés |
|---|---|---|---|
| Administrateur technique | `admin` | Globale | Administration technique, accès à tous les modules |
| Super-Admin | `super_admin` | Globale | Paramétrage applicatif (workflow, calculs, apparence, templates d'export, simulation) |
| DG | `dg` | Globale | Validation/verrouillage stratégique, vue consolidée, financement DG, alertes critiques |
| Cabinet | `cabinet` | Globale (lecture) | Consultation pilotage/reporting/audit, peu d'écriture |
| Planification | `planification` | Globale | Structuration PAS, supervision PAO/PTA, consolidation, reporting |
| Direction | `direction` | Direction | Gestion PAO de sa direction, supervision PTA et actions, validation direction |
| Services | `service` (UI : « SERVICES ») | Direction + Service | Gestion PTA, création/suivi actions, validation chef-service |
| Agent | `agent` | Service (restreint) | Saisie hebdomadaire, sous-actions, justificatifs, demande de clôture |

### 0.3 Conventions transverses

- **Cycle de validation** : tous les plans (PAS, PAO, PTA) suivent le même cycle `brouillon → soumis → valide → verrouille` (enum SQL, cf. migrations `2026_02_21_100200`, `100500`, `100600`). Quatre actions de workflow sont disponibles : `submit`, `approve`, `lock`, `reopen`. Chaque transition appelle `WorkflowSettings` (rôle autorisé, statut cible) puis `WorkspaceNotificationService` et `recordAudit()`.
- **Verrouillage descendant** : un parent verrouillé bloque la modification des enfants ; toute tentative renvoie un message « plus etre modifié » côté contrôleur.
- **Exercices budgétaires** : `ExerciceContext` injecte l'exercice actif. Les plans et actions sont scopés par `exercice_id`, ce qui empêche les fuites cross-année.
- **Délégations** : la table `delegations` autorise un utilisateur à agir au nom d'une direction/service pendant une fenêtre temporelle. Les Policies (`PasPolicy`, `PaoPolicy`, etc.) consomment `DelegationService`.
- **Audit immuable** : le trait `RecordsAuditTrail` + le contrôleur web persistent dans `journal_audit` chaque mutation sensible (`create`, `update`, `delete`, `submit`, `approve`, `lock`, `reopen`).
- **IA assistée, jamais autonome** : les imports PTA et les rapports IA produisent des propositions traçables ; aucune création d'action ni validation de rapport n'est définitive sans correction/validation humaine.

---

## 1. Workflow PAS — Plan d'Actions Stratégique

### 1.1 Finalité métier

Cadrer la stratégie pluriannuelle de l'ANBG. Le PAS est un document institutionnel élaboré au niveau Planification/DG ; il fixe les axes stratégiques et les objectifs stratégiques que les directions devront décliner. Il est unique pour une période donnée et engage toutes les directions rattachées (`pas_directions` est une table pivot).

### 1.2 Acteurs et habilitations

| Acteur | Rôle dans le workflow |
|---|---|
| Planification | Crée et alimente le PAS via le wizard (`workspace.pas.create`, `edit`). Soumet pour validation. |
| DG | Valide (`approve`) et verrouille (`lock`) après recette. |
| Administrateur / Super-Admin | Configure les transitions autorisées par rôle (`WorkflowSettings`). |
| Direction / Service / Agent | Consultation seule (le PAS est référencé en lecture par les écrans descendants). |

### 1.3 Déclencheurs

- Démarrage d'un nouveau cycle stratégique (nouvelle période ou exercice).
- Décision DG/Planification de réviser un PAS en cours (réouverture motivée).

### 1.4 Étapes (parcours métier)

1. **Création** (`PasWebController::create` puis `store` via `StorePasRequest`) : libellé, période, exercice, directions rattachées.
2. **Construction de la structure** dans le wizard PAS unifié :
   - Saisie des **axes** (`pas_axes`, contrôleur `PasAxeController` API).
   - Saisie des **objectifs stratégiques** rattachés à chaque axe (`pas_objectifs`, contrôleur `PasObjectifController` API).
   - Métadonnées d'alignement (`align_pas_structure_metadata` ajoute des champs après `statut`).
3. **Soumission** (`submit`) : Planification déclenche `pas/{pas}/submit`. La transition est conditionnée par `WorkflowSettings::submit_enabled`. Cible `soumis` (ou `valide` si le paramétrage autorise une validation directe).
4. **Validation** (`approve`) : DG/Cabinet (selon paramétrage) approuve. Le statut passe à `valide`.
5. **Verrouillage** (`lock`) : passage à `verrouille`. Plus aucune modification de structure n'est possible ; les PAO ne peuvent être créés que sur un PAS validé ou verrouillé (`Pao::scopeValidatedOrLocked`).
6. **Réouverture** (`reopen`) : Planification ou Admin, sur motif obligatoire, ramène le PAS à un statut antérieur autorisé (`WorkflowSettings::reopen_allowed_statuses`). L'événement est tracé en audit avec l'ancien et le nouveau statut.

### 1.5 Règles de gestion

- Routes legacy `pas-axes/*` et `pas-objectifs/*` sont redirigées vers le wizard PAS unique : aucun parcours métier séparé pour gérer axes ou objectifs.
- Suppression d'un PAS verrouillé interdite (`lockedStateMessage('PAS', 'etre supprime')`).
- La réouverture exige un motif (champ contrôlé par `WorkflowSettings::reopen_motif_required`).
- Notifications poussées via `WorkspaceNotificationService::notifyPasStatus()` à chaque transition (`submitted`, `approved`, `locked`, `reopened`).
- Audit : chaque action est journalisée (`recordAudit($request, 'pas', $action, $pas, $before, $after)`).

### 1.6 États et transitions

| État | Transitions possibles | Acteur typique |
|---|---|---|
| `brouillon` | → `soumis` (submit), suppression possible | Planification |
| `soumis` | → `valide` (approve), retour `brouillon` (reopen) | DG, Cabinet |
| `valide` | → `verrouille` (lock), retour `soumis`/`brouillon` (reopen) | DG |
| `verrouille` | → `valide` (reopen exceptionnel) | DG / Admin |

### 1.7 Données clés

`pas` (statut, période, exercice), `pas_axes`, `pas_objectifs`, `pas_directions` (rattachement multi-direction), `journal_audit` (traçabilité).

### 1.8 Exceptions et cas limites

- Validation refusée par `WorkflowSettings` → message bloquant côté contrôleur.
- Création d'un PAO sur un PAS non validé → bloqué via les scopes `validated()` / `validatedOrLocked()`.
- Réouverture d'un PAS référencé par des PAO verrouillés → impact sur l'aval, à apprécier par l'auditeur (l'application trace mais ne propage pas la réouverture).

### 1.9 KPI métier

- Taux de couverture du PAS par des PAO (% d'objectifs stratégiques déclinés au moins une fois en PAO par direction).
- Délai moyen entre `brouillon` et `verrouille` (mesurable via `journal_audit`).
- Nombre de réouvertures par cycle (indicateur de maturité de planification).

---

## 2. Workflow PAO — Plan d'Actions Opérationnel

### 2.1 Finalité métier

Décliner annuellement le PAS au niveau d'une direction. Chaque PAO traduit les objectifs stratégiques en objectifs opérationnels mesurables et alimente les PTA des services rattachés.

### 2.2 Acteurs

| Acteur | Rôle |
|---|---|
| Direction | Élabore et soumet le PAO de sa direction. |
| Planification | Peut piloter, supervise la cohérence avec le PAS. |
| DG / Cabinet | Valident, verrouillent. |
| Service / Agent | Lecture des objectifs opérationnels servant de cadre au PTA. |

### 2.3 Déclencheurs

- PAS validé/verrouillé → ouverture de la fenêtre d'élaboration des PAO.
- Nouvel exercice budgétaire (`ExerciceContext`).

### 2.4 Étapes

1. **Création** (`PaoWebController::store`) : sélection du PAS (validé/verrouillé), de la direction, de l'exercice, optionnellement du service de pilotage.
2. **Rattachement à un objectif stratégique** du PAS (`pas_objectif_id`, voir migration `2026_03_13_090000_relink_paos_to_pas_objectifs`).
3. **Construction de la structure PAO** :
   - **Axes PAO** (`pao_axes`).
   - **Objectifs stratégiques rattachés** (`pao_objectifs_strategiques`).
   - **Objectifs opérationnels** (`pao_objectifs_operationnels`, cible de rattachement des PTA).
4. **Soumission** (`submit`) → statut `soumis`.
5. **Validation** (`approve`) → `valide`.
6. **Verrouillage** (`lock`) → `verrouille`.
7. **Réouverture** (`reopen`) sur motif.

### 2.5 Règles de gestion

- Un PAO appartient à exactement un PAS + une direction + un exercice (`Pao::pas()`, `direction()`, `exercice()`).
- L'unicité par direction et par exercice est imposée par les contraintes (cf. `add_service_scope_to_paos_table`).
- Les PTA ne peuvent être rattachés qu'à un PAO `valide` ou `verrouille` (`scopeValidatedOrLocked`).
- Audit et notifications identiques au PAS, via `WorkspaceNotificationService::notifyPaoStatus()`.
- Couverture : le service `AlertCenterService` calcule l'alerte `missing_pao_coverage` lorsqu'un objectif stratégique du PAS n'a aucun PAO sur une direction donnée.

### 2.6 États et transitions

Identiques à PAS : `brouillon → soumis → valide → verrouille` avec réouverture motivée.

### 2.7 Données clés

`paos` (statut, exercice, échéance, pao→pas_objectif_id, direction_id, service_id optionnel), `pao_axes`, `pao_objectifs_strategiques`, `pao_objectifs_operationnels`.

### 2.8 Exceptions

- Tentative de soumission par un acteur non habilité → blocage par `WorkflowSettings`.
- Modification après verrouillage du PAS parent → blocage en cascade.
- Réouverture interdite si un PTA aval est verrouillé (à apprécier opérationnellement).

### 2.9 KPI

- Taux de couverture des objectifs stratégiques par direction.
- Délai de validation moyen.
- Nombre d'objectifs opérationnels par PAO (indicateur de granularité).

---

## 3. Workflow PTA — Plan de Travail Annuel

### 3.1 Finalité métier

Planifier les actions d'un service pour l'année, en rattachant chaque action à un objectif opérationnel d'un PAO validé. Le PTA est l'écrin opérationnel à partir duquel les actions sont créées : depuis l'évolution récente, la création d'action en dehors du PTA est explicitement bloquée (`actions/create` redirige vers `workspace.pta.index`).

### 3.2 Acteurs

| Acteur | Rôle |
|---|---|
| Chef de service (Services) | Élabore le PTA de son service, crée les actions, soumet et suit. |
| Planification | Peut piloter et superviser. |
| Direction | Valide. |
| DG / Cabinet | Vue consolidée, peuvent verrouiller selon paramétrage. |
| Agent | Lecture seule (sauf saisie hebdo dans les actions). |

### 3.3 Déclencheurs

- PAO de la direction validé/verrouillé.
- Démarrage de l'exercice annuel.

### 3.4 Étapes

1. **Création** (`PtaWebController::store`) : choix du PAO, de l'objectif opérationnel rattaché, de la direction, du service, de l'exercice.
2. **Élaboration** :
   - Description et objectifs du PTA.
   - Création des **actions** rattachées (`PtaWebController` orchestre la navigation vers la création d'action depuis le PTA).
3. **Soumission** (`submit`) → `soumis`.
4. **Validation** (`approve`) → `valide`. Une fois validé, les actions du PTA sont opposables et entrent en exécution.
5. **Verrouillage** (`lock`) → `verrouille`. Les actions associées passent en mode suivi/contrôle uniquement.
6. **Réouverture** motivée (`reopen`).

### 3.5 Règles de gestion

- Unicité PTA par PAO (`normalize_ptas_unique_per_pao` — un seul PTA actif par PAO et par service).
- Les actions sont obligatoirement créées dans un PTA (les routes `/actions/create` et `POST /actions` redirigent ou retournent `403` avec message explicite).
- Verrouillage descendant : un PTA verrouillé empêche la modification des actions structurantes.
- Audit complet via `recordAudit($request, 'pta', $action, $pta, $before, $after)`.
- Notifications de statut via `WorkspaceNotificationService::notifyPtaStatus()`.

### 3.6 États et transitions

Identiques à PAS/PAO : `brouillon → soumis → valide → verrouille` + `reopen`.

### 3.7 Données clés

`ptas` (statut, exercice, pao_id, objectif_operationnel_id, direction_id, service_id), `actions` (HasMany), `journal_audit`.

### 3.8 Exceptions

- Création d'action hors PTA : redirection ou erreur `403`.
- Tentative de réouverture si actions clôturées : à apprécier (l'application autorise mais ne déclôture pas les actions ; les actions terminées restent figées).

### 3.9 KPI

- Taux de validation des PTA par direction au début de l'exercice.
- Nombre d'actions par PTA.
- Cohérence avec PAO (taux de PTA rattachés à un objectif opérationnel actif).

---

## 4. Workflow Actions — création, suivi, validation, clôture

C'est le workflow le plus riche, structurant l'exécution opérationnelle. Il combine plusieurs sous-workflows : validation hiérarchique de l'action, financement DAF ↔ DG, suivi hebdomadaire, sous-actions, KPI et clôture.

### 4.1 Finalité métier

Piloter la réalisation concrète des décisions de planification : assigner une action à un agent, suivre sa progression, valider hiérarchiquement, financer si besoin, capitaliser les preuves (justificatifs), clôturer.

### 4.2 Acteurs

| Acteur | Rôle |
|---|---|
| Chef de service | Crée l'action (depuis le PTA), affecte un responsable, soumet pour validation. |
| Direction | Valide en seconde ligne après le chef de service. |
| Agent | Saisit la progression hebdomadaire, gère les sous-actions, dépose les justificatifs, demande la clôture. |
| DAF | Examine les demandes de financement, statue. |
| DG | Approuve ou refuse le financement final. |
| Planification / Cabinet / DG | Vue consolidée, supervision. |

### 4.3 Cycle de vie principal

**Statut métier d'exécution** (enum `statut` de la table `actions`) :
`non_demarre` → `en_cours` → `suspendu`/`termine`/`annule`.

**Statut dynamique** (calculé par `ActionStatusService`, attribut `statut_dynamique`) :
`non_demarre`, `en_cours`, `en_avance`, `en_retard`, `acheve_dans_delai`, `acheve_hors_delai`, `cloturee`.

**Statut de validation hiérarchique** (`statut_validation`, ajouté par `add_action_validation_workflow_fields`) :
`non_soumise` → `soumise_chef` → (`validee_chef` | `rejetee_chef`) → (`validee_direction` | `rejetee_direction`).

**Statut de financement** (constantes `Action::FINANCEMENT_*`) :
`non_requis` · `en_attente_daf` (alias `a_traiter_daf`) · `en_cours_analyse` · `approuve` (alias `valide_daf`) · `rejete` (alias `rejete_daf`) · `finance` (alias `accorde_dg`) · `non_finance` (alias `refuse_dg`).

### 4.4 Étapes — sous-workflow 1 : création et paramétrage

1. **Création dans le PTA** : champs obligatoires (libellé, pta_id, responsable, date_debut, date_fin_prevue, priorité, type_cible quantitative/qualitative).
2. **Définition de la cible** :
   - Quantitative : unité, `quantite_cible`, `mode_evaluation` (`quantitatif` ou `mixte`).
   - Qualitative : résultat attendu, critères d'achèvement, `mode_evaluation` (`sous_actions`).
3. **Indicateurs (KPI d'action)** : configurés directement dans l'écran action (les routes legacy `kpi/create` redirigent désormais ; seuls `kpi.store/update/destroy` sont conservés pour les opérations atomiques).
4. **Financement** : si `financement_requis = true`, saisie description, source, justificatif initial.
5. **Génération automatique des semaines** (`ActionWeek`) sur l'intervalle `[date_debut, date_fin_prevue]` (unicité `(action_id, numero_semaine)`).

### 4.5 Étapes — sous-workflow 2 : validation hiérarchique

1. **Soumission au chef de service** : `statut_validation` passe à `soumise_chef`, horodaté (`soumise_le`, `soumise_par`).
2. **Évaluation chef de service** (`actions/{action}/review`, `ActionTrackingWebController::reviewClosure`) : note (`evaluation_note`), commentaire, transition `validee_chef` ou `rejetee_chef`.
3. **Validation direction** (`actions/{action}/review-direction`, `reviewClosureByDirection`) : note, commentaire, transition `validee_direction` ou `rejetee_direction`. C'est la validation terminale métier.

### 4.6 Étapes — sous-workflow 3 : financement (DAF ↔ DG)

Routes dédiées sous `/actions/{action}/financement/...` :

1. **Action soumise avec financement requis** → `en_attente_daf`.
2. **Examen DAF** (`actions.financement.daf`) : DAF peut requalifier en `en_cours_analyse`, `approuve` ou `rejete`. Action `daf/financements-actions` agrège la file DAF.
3. **Mise à jour du statut financier par DAF** (`actions.financement.daf.status`).
4. **Décision DG** (`actions.financement.dg`) : `finance` ou `non_finance`. Une action `finance` peut entrer en exécution ; une action `non_finance` ne peut plus engager de ressources.
5. **Notifications** poussées à chaque transition vers les acteurs concernés via `WorkspaceNotificationService` et `AlertCenterService`.

### 4.7 Étapes — sous-workflow 4 : suivi opérationnel

1. **Semaines générées** automatiquement à la création (`ActionWeek`).
2. **Saisie hebdomadaire** (`actions/{action}/semaines/{actionWeek}/soumettre`, `ActionTrackingWebController::submitWeek`) :
   - Champs communs : difficultés, mesures correctives, justificatif hebdo.
   - Quantitatif : `quantite_realisee` cumulée.
   - Qualitatif / sous-actions : avancement estimé 0-100 %, tâches réalisées.
3. **Sous-actions** (`SousAction`, `actions.sub-actions.store`/`update`) : décomposition fine de l'action pour les modes `sous_actions` et `mixte`.
4. **Mise à jour rapide de statut** (`PATCH actions/{action}/quick-statut`) pour basculer `en_cours`/`suspendu`/`termine`.
5. **Mise à jour de l'exécution quantitative** (`actions.execution.update`).
6. **Recalcul automatique** : `ActionObserver` déclenche `recalculateRealization()` et `ActionPerformanceService` à chaque sauvegarde ; les seuils de retard et de KPI sont paramétrables (`ActionCalculationSettings`).
7. **Commentaires** (`actions.comment`) : discussion sur l'action, persistée et tracée.
8. **Justificatifs** (`Justificatif`, polymorphe `MorphMany`) : dépôt, prévisualisation, téléchargement contrôlés (`actions.justificatifs.preview`/`download`) — stockage sécurisé via `SecureJustificatifStorage` (scan antivirus avant persistance).

### 4.8 Étapes — sous-workflow 5 : clôture

1. **Demande de clôture** par l'agent : saisie `date_fin_reelle`, `rapport_final`, dépôt `justificatif_final` obligatoire.
2. **Revue chef de service** (`actions.review`) : note finale, commentaire, validation ou rejet.
3. **Revue direction** (`actions.review-direction`) : décision terminale.
4. Le statut dynamique bascule à `cloturee` ; l'action devient lecture-seule pour l'exécution (les KPI restent calculés pour le reporting).

### 4.9 Règles de gestion majeures

- Toute action est créée depuis un PTA validé/verrouillé ; sinon redirection ou 403.
- L'agent ne peut **pas** créer ou supprimer une action ; il ne saisit que les semaines, sous-actions, justificatifs et la demande de clôture (politique RBAC + `ActionPolicy`).
- Unicité : `(action_id, numero_semaine)` pour `action_weeks` ; un seul `ActionKpi` primaire par action (`primaryKpi`).
- Justificatifs : un système polymorphe unique remplace l'ancienne table `action_justificatifs` (cf. migration `migrate_action_justificatifs_to_justificatifs`).
- Verrouillage descendant : si le PTA parent est `verrouille`, les champs structurants de l'action sont figés ; seuls les champs de suivi restent ouverts.
- Recalcul auto : à chaque save d'action, l'`ActionObserver` invalide les caches agrégés (`PlanningCacheObserver`).

### 4.10 Données clés

`actions` (statut, statut_dynamique, statut_validation, financement, dates, responsable, mode_evaluation, type_cible, quantite_cible / resultat_attendu), `action_weeks`, `action_kpis`, `action_logs`, `sous_actions`, `justificatifs` (polymorphe), `kpis`, `kpi_mesures`.

### 4.11 Exceptions et cas limites

- Saisie hebdo manquante → alerte `action_overdue` ou `action_non_demarre`.
- Financement requis sans justificatif → alerte (paramétrable).
- Tentative de modification après clôture → bloquée ; correction = réouverture du PTA, motif obligatoire.
- Action avec responsable inactif (`EnsureActiveAccount` bloque l'utilisateur) → l'action reste mais la saisie est bloquée ; le chef de service doit réaffecter.

### 4.12 KPI calculés (formules)

- **Progression théorique** : fonction du temps écoulé entre `date_debut` et `date_fin_prevue`.
- **Progression réelle** :
  - Quantitative : `quantite_cumulee / quantite_cible × 100`.
  - Qualitative : `avancement_estime` (ou moyenne pondérée des sous-actions en mode `sous_actions`).
- **KPI global d'action** : combinaison `délai · performance · qualité · risque`, pondération définie par `ActionCalculationSettings`.
- **Seuils** paramétrables : `seuil_retard`, `seuil_alerte_kpi_global`.

---

## 5. Workflow Alertes

### 5.1 Finalité métier

Détecter en temps quasi-réel les écarts par rapport au plan et orienter les acteurs vers la cause via un centre d'alertes unifié. Les alertes alimentent à la fois l'UI (dropdown header, page dédiée), les notifications, et un digest e-mail (`AlertDigestMail` via `SendAlertDigestJob`).

### 5.2 Acteurs

| Acteur | Rôle |
|---|---|
| Tous (selon scope) | Reçoivent des alertes filtrées par leur périmètre (`AlertRoutingService`). |
| Agent | Reçoit les alertes liées à ses actions assignées. |
| Service / Direction / DG | Reçoivent en escalade selon ancienneté de l'alerte. |
| Planification / Cabinet | Vue globale. |

### 5.3 Sources et types d'alertes (vu dans `AlertCenterService`)

- `action_overdue` : action en retard ou non démarrée (sous-types `retard` ou `action_non_demarre`).
- `kpi_breach` : KPI sous seuil, sous-types `kpi_global` ou `kpi_sous_seuil`.
- `action_log` : événement d'audit applicatif d'intérêt (saisie manquante, rejet de validation, etc.).
- `missing_pao_coverage` : objectif stratégique non décliné en PAO sur une direction (`pao_manquant`).
- `delegation_expiring` : délégation expirant prochainement (`delegation_expiration`).

### 5.4 Niveaux et compteurs

`urgence`, `critical`, `warning`, `info`. Le service calcule les volumes totaux et non lus par niveau et conserve un cache key incluant l'utilisateur, son rôle et son périmètre.

### 5.5 Étapes (parcours utilisateur)

1. **Collecte** : `AlertCenterService::collectForUser($user, $limit)` agrège les items pour l'utilisateur courant en respectant son périmètre.
2. **Filtrage** : `AlertRoutingService` applique les règles de visibilité (par direction/service, agent, délégation).
3. **Affichage** : page `workspace.alertes` (liste filtrable) + dropdown `workspace.alertes.dropdown` (compteurs et items récents).
4. **Lecture** : un clic sur une alerte appelle `workspace.alertes.read` (typée) qui marque l'alerte comme lue (`AlertRead` + `alert_reads`) et redirige vers la cause (action, KPI, PAO manquant, délégation).
5. **Tout marquer comme lu** : `workspace.alertes.read_all`.
6. **Digest e-mail** : `SendAlertDigestJob` envoie un récapitulatif périodique via `AlertDigestMail` (SMTP).

### 5.6 Escalade

Modèle d'escalade paramétrable (cf. `NotificationPolicySettings`) typiquement :

- J+0 : agent / responsable direct.
- J+3 : chef de service.
- J+7 : direction.
- J+15 : DG.

### 5.7 Règles de gestion

- L'idempotence est garantie par un `fingerprint` SHA-1 par item (`reads` versionnés).
- Les alertes ne sont pas stockées comme des entités mais reconstruites à chaque requête + lecture persistée. C'est volontaire (état toujours frais), mais cela suppose un index correct côté actions/KPI/logs.
- Une alerte cesse d'apparaître quand sa cause disparaît (saisie effectuée, KPI redevenu vert, délégation prolongée).

### 5.8 KPI

- Nombre d'alertes ouvertes par direction / par jour.
- Délai moyen de lecture par niveau.
- Taux d'alertes escaladées au-delà du chef de service.

---

## 6. Workflow Pilotage et Reporting

### 6.1 Finalité métier

Donner une vue consolidée et exportable de l'avancement à différents niveaux (DG, Direction, Service, Cabinet), et produire les livrables de reporting (Excel, PDF).

### 6.2 Acteurs

| Acteur | Rôle |
|---|---|
| DG / Cabinet | Vue stratégique (consolidation toutes directions). |
| Planification | Pilotage transversal, analyses, exports. |
| Direction | Vue de sa direction. |
| Service | Vue de son service. |
| Agent | Vue de ses propres actions. |

Les dashboards sont rôle-aware : le `DashboardController` construit un agrégat différent pour chaque rôle (six variantes : agent, service, direction, planification, dg, cabinet).

### 6.3 Étapes

1. **Connexion** → redirection vers `/dashboard` (`DashboardController::index`) qui choisit la variante selon le rôle.
2. **Pilotage** : redirige aujourd'hui vers `/dashboard` (`Route::redirect('/workspace/pilotage', '/dashboard')`).
3. **Reporting analytique** : `workspace.reporting` (`MonitoringWebController::reporting`) avec filtres (exercice, direction, service, statut, priorité).
4. **Export Excel** : `workspace.reporting.export.excel` (synchrone via `app/Services/Exports`).
5. **Export PDF** : `workspace.reporting.export.pdf` (Dompdf via `barryvdh/laravel-dompdf`).
6. **Export asynchrone** : `workspace.reporting.export.queue` pose un job en file (queue `database`), puis `workspace.reporting.exports.download` (signed + throttle `api-downloads`) sert le fichier produit.
7. **Templates d'export configurables** : Super-Admin → `templates-export` (création, versioning, publication, archivage, duplication, prévisualisation, assignation à un module).

### 6.4 Règles de gestion

- Les URLs de téléchargement d'exports asynchrones sont **signed** (anti-tampering) et **throttled** (`api-downloads`, 30 req/min) pour bloquer le scraping massif.
- Les filtres reporting respectent le périmètre RBAC (`AccessScopeService`).
- Les caches dashboard sont invalidés par `PlanningCacheObserver` à chaque modification de PAO/PTA/User et par `ActionObserver` à chaque modification d'action.

### 6.5 Données et services impliqués

`ReportingAnalyticsService`, `app/Services/Exports/*`, `ExportTemplate`, `ExportTemplateVersion`, `ExportTemplateAssignment`, `DashboardProfileSettings`, `GenerateReportJob`.

### 6.5.a Sous-workflow IA — Import PTA

1. **Upload** : un utilisateur habilité ouvre `workspace.ai-imports.pta.index` et dépose un fichier PTA (`csv`, `xlsx`, `pdf`, `doc`, `docx`, image). Le fichier est stocké sur le disque local non public.
2. **Analyse** : `PtaExtractionService` extrait les lignes tabulaires quand le format le permet. Les formats non tabulaires créent une ligne de travail à faible confiance, sans inventer de données.
3. **Normalisation** : `PtaNormalizationService` mappe les colonnes vers les champs PTA attendus, normalise statuts/budgets/dates et crée les lignes `ai_import_rows`.
4. **Prévisualisation** : l'utilisateur consulte les lignes, erreurs, avertissements et scores de confiance avant toute écriture métier.
5. **Correction humaine** : les lignes peuvent être corrigées ou ignorées. Les corrections sont enregistrées dans `corrected_data`.
6. **Validation** : `PtaImportValidationService` bloque les lignes incomplètes ou incohérentes. Les erreurs restent visibles et exportables.
7. **Import final** : `PtaFinalImportService` réexécute la validation, crée au besoin les conteneurs PAS/PAO/PTA, puis crée uniquement les actions issues des lignes valides.
8. **Historique** : chaque étape sensible est tracée dans `ai_import_audits` et consultable via `workspace.ai-imports.pta.history`.

### 6.5.b Sous-workflow IA — Rapports PAS/PAO/PTA

1. **Sélection** : l'utilisateur habilité choisit le type de rapport (`pas`, `pao`, `pta`) et le périmètre (exercice, direction, service).
2. **Calcul métrique** : les builders `PasReportDataBuilder`, `PaoReportDataBuilder`, `PtaReportDataBuilder` s'appuient sur les actions Laravel existantes ; le rapport ne dépend pas d'une réponse IA non vérifiée pour ses chiffres.
3. **Rédaction assistée** : `AiReportWritingService` produit un brouillon structuré avec synthèse, analyse, risques et recommandations.
4. **Correction** : le contenu peut être modifié avant validation.
5. **Validation humaine** : `ReportValidationService` marque le rapport comme validé et conserve l'utilisateur/date de validation.
6. **Exports** : seuls les contenus contrôlés sont exportés en PDF, Word et Excel par `ReportExportService`.

### 6.6 Exceptions

- Volume important + filtre large : risque de timeout (l'absence de pagination sur `reporting/overview` est un point d'attention identifié dans l'audit).
- Template d'export archivé : non sélectionnable en assignation, mais conservé pour historique.

### 6.7 KPI

- Délai moyen de génération d'un export.
- Taux d'export téléchargés vs queueés.
- Nombre d'utilisateurs actifs par dashboard.

---

## 7. Workflow Référentiels, Rôles et Délégations

### 7.1 Finalité métier

Maintenir le socle organisationnel et de sécurité : structures (directions, services), utilisateurs, rôles/permissions, unités DG (DGA, SCIQ, Cabinet, UCAS), délégations temporaires.

### 7.2 Acteurs

| Acteur | Rôle |
|---|---|
| Administrateur / Super-Admin | CRUD direction/service/utilisateur, gestion des rôles et permissions, paramétrage organisation. |
| Direction | Peut, selon paramétrage, gérer les utilisateurs de sa direction. |
| Tous | Peuvent créer une délégation entrante/sortante selon `RolePermissionSettings`. |

### 7.3 Sous-workflow 7a : référentiels organisationnels

- **Directions** : CRUD (`workspace.referentiel.directions.*`). Suppression bloquée si services rattachés actifs.
- **Services** : CRUD (`workspace.referentiel.services.*`) avec contrôle `service.direction_id`.
- **Utilisateurs** : CRUD (`workspace.referentiel.utilisateurs.*`). `direction_id` et `service_id` obligatoires si rôle Agent/Service/Direction. Réinitialisation mot de passe par admin (`organization.users.reset-password`), révocation de sessions (`organization.users.revoke-sessions`), import en masse (`organization.users.import`), opérations bulk (`organization.users.bulk`).
- **Organisation Super-Admin** : panneau dédié `super-admin/organisation-utilisateurs` qui consolide Directions + Services + Utilisateurs avec toggles d'activation et import CSV.

### 7.4 Sous-workflow 7b : rôles et permissions

- Panneau `super-admin/roles-permissions` (`SuperAdminWebController::rolesEdit`/`rolesUpdate`).
- Modèle `RolePermissionSettings` + `RoleRegistryService` : ajout, duplication, restauration de version (`roles.registry.restore/{versionId}`).
- Flags supportés : `scope.global.read/write`, `scope.direction.read/write`, `scope.service.read/write`, plus capacités fines par module.

### 7.5 Sous-workflow 7c : unités DG

- `unites_dg` (DGA, SCIQ, Cabinet, UCAS). Panneau `super-admin/unites-dg`.
- Désignation du chef d'unité (`unites-dg.set-chef`) — service `ChefUniteSyncService` maintient la cohérence des rôles.

### 7.6 Sous-workflow 7d : délégations

- Création : `workspace.delegations.create/store` (GovernanceWebController).
- Annulation : `workspace.delegations.cancel`.
- Modèle : `Delegation` (délégant, délégataire, direction et/ou service portés, fenêtre `valid_from`/`valid_to`, motif).
- Cache per-request : `User::activeDelegations()` ; consommé par les Policies (`PasPolicy`, `PaoPolicy`, `ActionPolicy`).
- Alerte : `delegation_expiring` est levée à l'approche de l'expiration.

### 7.7 Règles de gestion

- Périmètre dynamique : un acteur peut être restreint à sa direction/service par défaut et étendu temporairement par une délégation.
- Toute mutation organisationnelle est auditée.
- La suppression d'un utilisateur référencé (action, validation, audit) est interdite ; on désactive (`organization.users.toggle`).

### 7.8 KPI

- Nombre de délégations actives / expirées.
- Couverture organisationnelle : % d'utilisateurs avec `direction_id` + `service_id` cohérents.

---

## 8. Workflow Audit, Rétention, Notifications, Profil

### 8.1 Audit

**Finalité.** Tracer immuablement toutes les opérations sensibles pour pouvoir reconstruire l'histoire d'une décision.

**Mécanique.**
- Trait `RecordsAuditTrail` + appels explicites `recordAudit($request, $module, $action, $entity, $before, $after)`.
- Table `journal_audit` : `module`, `entite_type`, `entite_id`, `action` (`create/update/delete/submit/approve/lock/reopen/...`), `ancienne_valeur`, `nouvelle_valeur`, `user_id`, `date`, `IP`.
- Accès via `workspace.audit.index` (rôles avec capacité `audit.read`).
- Service `PlatformDiagnosticService` + panneau `super-admin/audit-diagnostic` pour les vérifications transverses.

**Règles.** Le journal est append-only ; toute modification est interdite. Les recherches sont filtrables (module, acteur, période).

### 8.2 Rétention

**Finalité.** Purger ou archiver les données obsolètes conformément à la politique de rétention.

**Mécanique.**
- Modèle `DataArchive` (créé par `create_data_archives_table`) — entrepôt local des données archivées.
- Page `workspace.retention.index` (lecture, configuration, exécution manuelle via `workspace.retention.run`).
- Exécution périodique attendue (job ou commande artisan).

**Règles.** Le journal d'audit n'est jamais purgé ; seules les données opérationnelles peuvent être archivées selon la politique configurée.

### 8.3 Notifications

**Finalité.** Informer les acteurs en temps réel des événements qui les concernent (changements de statut PAS/PAO/PTA, validation d'action, décision de financement, messages).

**Mécanique.**
- Modèle `Notification` (table `notifications`).
- Service `WorkspaceNotificationService` : `notifyPasStatus`, `notifyPaoStatus`, `notifyPtaStatus`, `notifyActionStatus`, etc.
- Canaux : base (UI), broadcast temps réel via Laravel Echo + Pusher (`channels.php`), e-mail digest (`AlertDigestMail`).
- Lecture : `workspace.notifications.read/{notification}`, `workspace.notifications.read_all`.
- Panneau Super-Admin `alertes-notifications` pour paramétrer la politique (canaux, seuils, escalade) — `NotificationPolicySettings`.

**Règles.** Une notification non lue reste visible dans le dropdown header ; une fois lue, elle disparaît de la file mais est conservée pour audit/statistiques.

### 8.4 Profil utilisateur

**Finalité.** Permettre à l'utilisateur de maintenir ses informations personnelles, sa photo, son mot de passe et de gérer ses sessions actives.

**Mécanique.**
- Pages : `workspace.profile.edit/update`.
- Sessions : `revoke-current`, `revoke-others`, `revoke/{sessionId}` — utile en cas de perte d'appareil.
- Politique mot de passe :
  - `PasswordHistory` empêche la réutilisation d'anciens mots de passe.
  - Middleware `EnsurePasswordIsFresh` force la rotation périodique (à intervalle paramétrable).
  - Politique de force (longueur, complexité) configurée dans `config/security.php`.
- Réinitialisation : flux `password.request → password.email → password.reset → password.update` (throttle 6/min sur l'envoi et la mise à jour).
- Compte désactivé : `EnsureActiveAccount` middleware bloque l'accès.

**Règles.**
- Tentative de connexion : throttle 5/10 min par e-mail + 25/10 min par IP (`AppServiceProvider::configureRateLimiting`).
- Photo de profil : stockée via `UserProfileService` (`profile_photo_path`).

### 8.5 KPI métier transverses

- Délai moyen de lecture des notifications.
- Volume d'événements d'audit par module et par mois (indicateur d'activité).
- Taux de rotation des mots de passe.
- Nombre de sessions actives par utilisateur (vigilance sécurité).

---

## 9. Vue end-to-end : enchaînement nominal

```
1. Structuration PAS                 (Planification → DG)
2. Déclinaison PAO par direction     (Direction)
3. Validation PAO                    (DG / Direction)
4. Déclinaison PTA par service       (Service)
5. Validation PTA                    (Direction)
6. Création des actions dans le PTA  (Service)
7. Génération automatique des semaines
8. Soumission de l'action            (Service → Chef Service → Direction)
9. Demande de financement (optionnel)(Service → DAF → DG)
10. Exécution + saisie hebdomadaire  (Agent)
11. Calcul automatique statuts/KPI   (ActionObserver + jobs)
12. Alertes et escalade              (AlertCenterService + SendAlertDigestJob)
13. Demande de clôture               (Agent)
14. Revue chef de service + direction
15. Reporting consolidé              (Planification / DG)
16. Audit immuable                   (transverse à chaque étape)
```

---

## 10. Matrice synthétique des workflows

| Workflow | Acteurs principaux | États clés | Verrou | Audit | Alertes |
|---|---|---|---|---|---|
| PAS | Planification, DG | brouillon→soumis→valide→verrouille | descendant sur PAO | ✓ | `missing_pao_coverage` |
| PAO | Direction, DG | idem PAS | descendant sur PTA | ✓ | `missing_pao_coverage` |
| PTA | Service, Direction | idem PAS | descendant sur actions structurantes | ✓ | dérive des actions |
| Action — exécution | Service, Agent | non_demarre→en_cours→termine/suspendu/annule (+ statut_dynamique) | sur PTA verrouillé | ✓ | `action_overdue`, `action_non_demarre` |
| Action — validation hiérarchique | Service, Direction | non_soumise→soumise_chef→validee_chef→validee_direction (+ rejets) | — | ✓ | via `action_log` |
| Action — financement | DAF, DG | non_requis · en_attente_daf · en_cours_analyse · approuve · rejete · finance · non_finance | — | ✓ | via `action_log` |
| Alertes | Tous (par scope) | non lu / lu | — | lecture journalisée | — |
| Pilotage / Reporting | DG, Cabinet, Planif. | — | — | — | — |
| Import IA PTA | Planification, SCIQ, Direction/Service habilités | uploaded→analyzed→validated→imported | validation humaine avant import | ✓ (`ai_import_audits`) | erreurs exportables |
| Rapports IA PAS/PAO/PTA | Planification, DG, Cabinet, Direction habilitée | draft→validated→exported | validation humaine avant export officiel | ✓ (`ai_generated_reports`) | — |
| Référentiels | Admin, Super-Admin | actif/inactif | — | ✓ | — |
| Délégations | Tous (sur scope) | active / annulée / expirée | — | ✓ | `delegation_expiring` |
| Audit | (lecture) | append-only | — | — | — |
| Rétention | Admin, Super-Admin | archivé / purgé | — | ✓ | — |
| Notifications | Tous | non lu / lu | — | — | — |
| Profil | Utilisateur | actif / désactivé / sessions révoquées | — | ✓ (sécurité) | — |

---

## 11. Points d'attention fonctionnels

Identifiés au croisement de l'analyse de code et de l'audit existant (`docs/analyse-globale-application.md`) :

1. **Propagation de la réouverture** : la réouverture d'un plan parent (PAS ou PAO) ne déclenche pas mécaniquement de re-validation des plans enfants ; à clarifier en termes de processus (gel manuel ? alerte ?).
2. **Pas d'idempotence explicite sur la génération des semaines** lors d'un changement de `date_fin_prevue` après création : à vérifier que `ActionWeek` est recalculé correctement sans casser l'historique de saisies.
3. **Couverture PAO** : l'alerte `missing_pao_coverage` est utile mais ne propose pas d'action « créer le PAO manquant » en un clic ; opportunité UX.
4. **Financement vs exécution** : une action `approuve` (DAF) mais non encore `finance` (DG) peut-elle démarrer son exécution ? À tracer dans une règle de gestion explicite.
5. **Rétention vs audit** : la politique de purge doit explicitement exclure `journal_audit` ; à confirmer dans le code de rétention.
6. **Alertes calculées à la volée** : performances à surveiller en volume (>5 000 actions ouvertes) — l'audit a déjà recommandé d'introduire la pagination cursor sur les endpoints reporting et probablement sur la collecte des alertes.

---

## 12. Glossaire

- **PAS** — Plan d'Actions Stratégique (pluriannuel, institutionnel).
- **PAO** — Plan d'Actions Opérationnel (annuel, niveau direction).
- **PTA** — Plan de Travail Annuel (annuel, niveau service).
- **Action** — unité opérationnelle de travail (remplace l'ancienne notion « Activité » supprimée).
- **ActionWeek** — semaine de suivi générée automatiquement entre `date_debut` et `date_fin_prevue`.
- **Sous-action** — décomposition d'une action en tâches plus fines (mode `sous_actions` ou `mixte`).
- **Justificatif** — pièce probante stockée de façon sécurisée (catégories : financement, hebdomadaire, final).
- **KPI** — indicateur de pilotage (délai, performance, qualité, risque, combinés en un KPI global d'action).
- **Délégation** — autorisation temporaire d'agir au nom d'une direction/service.
- **Exercice** — période budgétaire de référence (`ExerciceContext`).

---

_Fin du document._
