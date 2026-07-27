# Analyse fonctionnelle des workflows métier

**Application ANBG — Pilotage PAS / PAO / PTA / Actions**

- Version : 2.0
- Date : 27 juillet 2026
- Périmètre : tous les workflows métier de l'application Laravel courante
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

À chaque niveau s'appliquent : un cycle opérationnel propre au plan, une journalisation d'audit (`journal_audit`), une gestion d'alertes (`AlertCenterService`) et un périmètre de droits dérivé du rôle de l'utilisateur (RBAC + scope direction/service + délégations). Les anciennes routes génériques `submit`, `approve`, `lock` et `reopen` ont été supprimées ; le cycle Web courant repose sur l'activité, le contrôle éventuel, la clôture, l'archivage et les demandes gouvernées de déverrouillage.

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

- **Cycles des plans** : PAS `actif → cloture → archive` ; PAO `en_cours/valide → cloture → archive` ; PTA `brouillon → en_cours → controle_sciq → cloture → archive`.
- **Protection des modifications** : un plan archivé est non modifiable. Les modifications exceptionnelles d'un PAS, d'un PTA ou d'une action protégée passent par une demande de déverrouillage lorsqu'une route dédiée existe.
- **Exercices budgétaires** : `ExerciceContext` injecte l'exercice actif. Les plans et actions sont scopés par `exercice_id`, ce qui empêche les fuites cross-année.
- **Délégations** : la table `delegations` autorise un utilisateur à agir au nom d'une direction/service pendant une fenêtre temporelle. Les Policies (`PasPolicy`, `PaoPolicy`, etc.) consomment `DelegationService`.
- **Audit immuable** : le trait `RecordsAuditTrail` et les contrôleurs Web persistent dans `journal_audit` chaque mutation sensible (`create`, `update`, `delete`, `submit_control`, `close`, `archive`, décisions et corrections).
- **IA assistée, jamais autonome** : les imports PTA et les rapports IA produisent des propositions traçables ; aucune création d'action ni validation de rapport n'est définitive sans correction/validation humaine.

---

## 1. Workflow PAS — Plan d'Actions Stratégique

### 1.1 Finalité métier

Cadrer la stratégie pluriannuelle de l'ANBG. Le PAS est un document institutionnel élaboré au niveau Planification/DG ; il fixe les axes stratégiques et les objectifs stratégiques que les directions devront décliner. Il est unique pour une période donnée et engage toutes les directions rattachées (`pas_directions` est une table pivot).

### 1.2 Acteurs et habilitations

| Acteur | Rôle dans le workflow |
|---|---|
| Planification | Crée et alimente le PAS via le wizard (`workspace.pas.create`, `edit`). Soumet pour validation. |
| DG | Supervise le cycle stratégique et les décisions institutionnelles selon les habilitations. |
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
3. **Exploitation active** : le PAS est créé au statut `actif` et peut être complété par les acteurs habilités.
4. **Contrôle de clôture** (`pas/{pas}/cloturer`) : l'application analyse les PAO, PTA, actions, validations, retards et KPI liés. Une clôture avec anomalies exige une justification explicite.
5. **Clôture** : le statut passe à `cloture` et la décision est auditée.
6. **Archivage** (`pas/{pas}/archiver`) : uniquement après clôture ; le PAS passe à `archive` et devient non modifiable.
7. **Déverrouillage exceptionnel** : une demande motivée utilise le circuit `pas/{pas}/demandes-deverrouillage` lorsqu'une correction postérieure est nécessaire.

### 1.5 Règles de gestion

- Routes legacy `pas-axes/*` et `pas-objectifs/*` sont redirigées vers le wizard PAS unique : aucun parcours métier séparé pour gérer axes ou objectifs.
- La modification et la suppression directe d'un PAS archivé sont interdites.
- L'archivage exige un PAS clôturé.
- La clôture contrôle les anomalies descendantes et exige une justification lorsqu'elles subsistent.
- Les transitions sont auditées avec l'ancien et le nouvel état.
- Audit : chaque action est journalisée (`recordAudit($request, 'pas', $action, $pas, $before, $after)`).

### 1.6 États et transitions

| État | Transitions possibles | Acteur typique |
|---|---|---|
| `actif` | → `cloture` après contrôle des anomalies | Planification / acteur habilité |
| `cloture` | → `archive` | Acteur habilité |
| `archive` | aucune modification directe | Consultation |

### 1.7 Données clés

`pas` (statut, période, exercice), `pas_axes`, `pas_objectifs`, `pas_directions` (rattachement multi-direction), `journal_audit` (traçabilité).

### 1.8 Exceptions et cas limites

- Validation refusée par `WorkflowSettings` → message bloquant côté contrôleur.
- Création d'un PAO sur un PAS non validé → bloqué via les scopes `validated()` / `validatedOrLocked()`.
- Une correction postérieure à la protection du plan doit passer par la demande de déverrouillage ; aucune réouverture directe n'est exposée.

### 1.9 KPI métier

- Taux de couverture du PAS par des PAO (% d'objectifs stratégiques déclinés au moins une fois en PAO par direction).
- Délai moyen entre la création, la clôture et l'archivage (mesurable via `journal_audit`).
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
| DG / Cabinet | Consultent la consolidation et exercent les arbitrages autorisés. |
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
4. **Validation à l'enregistrement** : le PAO complet est positionné à `valide` par le contrôleur Web courant.
5. **Exploitation** : les objectifs opérationnels validés alimentent les PTA des services.
6. **Clôture** (`pao/{pao}/cloturer`) après contrôle des anomalies descendantes.
7. **Archivage** (`pao/{pao}/archiver`) uniquement depuis l'état clôturé.

### 2.5 Règles de gestion

- Un PAO appartient à exactement un PAS + une direction + un exercice (`Pao::pas()`, `direction()`, `exercice()`).
- L'unicité par direction et par exercice est imposée par les contraintes (cf. `add_service_scope_to_paos_table`).
- Les PTA ne peuvent être rattachés qu'à un PAO `valide` ou `verrouille` (`scopeValidatedOrLocked`).
- Audit et notifications identiques au PAS, via `WorkspaceNotificationService::notifyPaoStatus()`.
- Couverture : le service `AlertCenterService` calcule l'alerte `missing_pao_coverage` lorsqu'un objectif stratégique du PAS n'a aucun PAO sur une direction donnée.

### 2.6 États et transitions

Cycle Web courant : `en_cours/valide → cloture → archive`. Les anciennes routes `submit`, `approve`, `lock` et `reopen` ne sont plus exposées.

### 2.7 Données clés

`paos` (statut, exercice, échéance, pao→pas_objectif_id, direction_id, service_id optionnel), `pao_axes`, `pao_objectifs_strategiques`, `pao_objectifs_operationnels`.

### 2.8 Exceptions

- Modification ou suppression d'un PAO archivé : blocage serveur.
- Archivage d'un PAO non clôturé : blocage serveur.
- Clôture avec anomalies descendantes : rapport et justification obligatoires.

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
| DG / Cabinet | Vue consolidée et arbitrages institutionnels selon habilitation. |
| Agent | Lecture seule (sauf saisie hebdo dans les actions). |

### 3.3 Déclencheurs

- PAO de la direction validé/verrouillé.
- Démarrage de l'exercice annuel.

### 3.4 Étapes

1. **Création** (`PtaWebController::store`) : choix du PAO, de l'objectif opérationnel rattaché, de la direction, du service, de l'exercice.
2. **Élaboration** :
   - Description et objectifs du PTA.
   - Création des **actions** rattachées (`PtaWebController` orchestre la navigation vers la création d'action depuis le PTA).
3. **Paramétrage des actions** : tant qu'une action reste à paramétrer, le PTA ne peut pas entrer normalement en exécution.
4. **Mise en cours** : lorsque toutes les actions sont paramétrées, le PTA passe de `brouillon` à `en_cours`.
5. **Soumission au contrôle SCIQ** (`pta/{pta}/cloturer` depuis `en_cours`) : statut `controle_sciq`.
6. **Clôture** (`pta/{pta}/cloturer` depuis `controle_sciq`) : statut `cloture`, avec rapport d'anomalies tracé si nécessaire.
7. **Archivage** (`pta/{pta}/archiver`) uniquement après clôture.
8. **Déverrouillage exceptionnel** : demande motivée via `pta/{pta}/demandes-deverrouillage`.

### 3.5 Règles de gestion

- Unicité PTA par PAO (`normalize_ptas_unique_per_pao` — un seul PTA actif par PAO et par service).
- Les actions sont obligatoirement créées dans un PTA (les routes `/actions/create` et `POST /actions` redirigent ou retournent `403` avec message explicite).
- Un PTA archivé ne peut plus être modifié ni recevoir d'action.
- Une modification exceptionnelle d'un PTA ou d'une action protégée passe par le circuit de déverrouillage prévu.
- Audit complet via `recordAudit($request, 'pta', $action, $pta, $before, $after)`.
- Notifications de statut via `WorkspaceNotificationService::notifyPtaStatus()`.

### 3.6 États et transitions

Cycle courant : `brouillon → en_cours → controle_sciq → cloture → archive`.

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
| Planification / SCIQ | Contrôle en seconde ligne après le visa du chef de service. |
| Agent | Saisit la progression, gère ses sous-actions, dépose les justificatifs et soumet son suivi. |
| DAF | Examine les demandes de financement, statue. |
| DG | Approuve ou refuse le financement final. |
| Planification / Cabinet / DG | Vue consolidée, supervision. |

### 4.3 Cycle de vie principal

**Statut métier d'exécution** (enum `statut` de la table `actions`) :
`non_demarre` → `en_cours` → `suspendu`/`termine`/`annule`.

**Statut dynamique** (calculé par `ActionStatusService`, attribut `statut_dynamique`) :
`non_demarre`, `en_cours`, `en_avance`, `en_retard`, `acheve_dans_delai`, `acheve_hors_delai`, `cloturee`.

**Statut de validation hiérarchique** (`statut_validation`, ajouté par `add_action_validation_workflow_fields`) :
`non_soumise` → `soumise_chef` → (`soumise_controle` | `rejetee_chef`/`correction_demandee`) → (`validee_controle` | `correction_controle`).

**Statut de financement** (constantes `Action::FINANCEMENT_*`) :
`non_requis` · `en_attente_daf` (alias `a_traiter_daf`) · `en_cours_analyse` · `approuve` (alias `valide_daf`) · `rejete` (alias `rejete_daf`) · `finance` (alias `accorde_dg`) · `non_finance` (alias `refuse_dg`).

### 4.4 Étapes — sous-workflow 1 : création et paramétrage

1. **Création dans le PTA** : champs obligatoires (libellé, pta_id, responsable, date_debut, date_fin_prevue, priorité, `type_action` pivot).
2. **Définition de la cible** :
   - `Q` / quantitative : unité, `quantite_cible`, `mode_evaluation = quantitatif`.
   - `NQ` / livrable unique : justificatif attendu, `mode_evaluation = sans_quantite`.
   - `M` / action composée : sous-actions proposées ou saisies, `mode_evaluation = sous_actions`.
   - Import IA PTA : `PtaActionParameterizationService` propose `type_action`, seuils, sous-actions, risque et alertes ; Laravel valide et l'utilisateur peut corriger avant import.
3. **Indicateurs (KPI d'action)** : configurés directement dans l'écran action (les routes legacy `kpi/create` redirigent désormais ; seuls `kpi.store/update/destroy` sont conservés pour les opérations atomiques).
4. **Financement** : si `financement_requis = true`, saisie description, source, justificatif initial.
5. **Initialisation du suivi** : l'action est prête pour une saisie cumulative ou par sous-actions. L'ancienne route de soumission hebdomadaire est neutralisée par une réponse `410`.

### 4.5 Étapes — sous-workflow 2 : validation hiérarchique

1. **Soumission au chef de service** : `statut_validation` passe à `soumise_chef`, horodaté (`soumise_le`, `soumise_par`).
2. **Visa chef de service** (`actions/{action}/review`) : validation vers `soumise_controle` ou retour motivé vers `correction_demandee`.
3. **Contrôle Planification/SCIQ** (`actions/{action}/controle`) : validation terminale vers `validee_controle` et clôture, ou retour motivé vers `correction_controle`.
4. **Correction** : le RMO corrige les données ou pièces sans effacer l'historique puis soumet à nouveau.

### 4.6 Étapes — sous-workflow 3 : financement (RMO → DAF → DG)

Routes dédiées sous `/actions/{action}/financement/...` :

1. **Preparation dans le PTA** : le besoin et sa piece initiale placent le dossier en `pre_signale_daf`.
2. **Soumission RMO** (`actions.financement.submit`) : confirmation de la source et de la note, puis passage a `soumis_daf`.
3. **Examen DAF** (`actions.financement.daf`) : avis favorable vers `transmis_dg`, complement vers `complement_demande`, ou rejet motive vers `rejete_daf`.
4. **Correction RMO** : complement ou rejet DAF autorisent une resoumission avec une nouvelle piece corrective.
5. **Decision DG** (`actions.financement.dg`) : passage terminal a `valide_dg` ou `rejete_dg`.
6. **Route historique neutralisee** : `actions.financement.daf.status` retourne `410` et ne peut plus produire un resultat DG.
7. **Notifications, audit et taches** sont emis a chaque transition et diriges vers le prochain acteur.

### 4.7 Étapes — sous-workflow 4 : suivi opérationnel

1. **Saisie de l'action** (`actions.execution.update`) : quantité réalisée, difficulté, commentaire et justificatif selon les règles du PTA.
2. **Saisie des sous-actions** (`actions.sub-actions.update`) : progression ou preuve de chaque élément affecté.
3. **Sauvegarde** : conservation d'un brouillon opérationnel sans déclencher le circuit complet.
4. **Soumission** : contrôles de complétude puis transmission au chef de service.
5. **Recalcul automatique** : `ActionObserver`, `ActionTrackingService` et `ActionPerformanceService` recalculent la progression, le statut dynamique, le délai et les KPI.
6. **Commentaires** (`actions.comment`) : discussion persistée et tracée.
7. **Justificatifs** : dépôt, prévisualisation et téléchargement contrôlés (`actions.justificatifs.preview`/`download`).
8. **Ancien suivi hebdomadaire** : la route de soumission par semaine est supprimée du circuit métier et retourne `410`.

### 4.8 Étapes — sous-workflow 5 : clôture

1. **Soumission du résultat** par l'agent ou le RMO avec les preuves exigées.
2. **Visa chef de service** (`actions.review`) : transmission au contrôle ou retour en correction.
3. **Contrôle final Planification/SCIQ** (`actions.control.review`) : validation ou correction motivée.
4. Après `validee_controle`, le statut bascule à `cloturee` et la performance devient officielle.

### 4.9 Règles de gestion majeures

- Toute action est créée ou paramétrée depuis le PTA ; la création directe depuis le portefeuille Actions est bloquée ou redirigée.
- L'agent ne peut **pas** créer ou supprimer une action ; il ne saisit que son suivi, ses sous-actions, justificatifs et commentaires.
- Un seul `ActionKpi` primaire est retenu par action (`primaryKpi`).
- Justificatifs : un système polymorphe unique remplace l'ancienne table `action_justificatifs` (cf. migration `migrate_action_justificatifs_to_justificatifs`).
- Un PTA archivé bloque les modifications. Les corrections exceptionnelles passent par le circuit de déverrouillage.
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
- Un template reste en `draft` tant qu'il n'est pas publié. Ses affectations initiales sont inactives et non définies par défaut.
- La publication verrouille le périmètre, crée un numéro de version unique, active les affectations cohérentes et remplace atomiquement le modèle et l'affectation par défaut du même périmètre.
- La modification d'un template publié le replace en brouillon ; l'archivage et la restauration neutralisent les affectations jusqu'à une nouvelle publication.
- Une affectation reprend obligatoirement `module`, `report_type` et `format` du template. Les doublons exacts et plusieurs défauts actifs sur un même périmètre sont interdits en service et par index unique en base.
- `ExportTemplateResolver` filtre les modèles publiés et choisit en SQL le périmètre le plus précis : service, direction, profil, niveau, puis défaut.

### 6.5 Données et services impliqués

`ReportingAnalyticsService`, `app/Services/Exports/*`, `ExportTemplate`, `ExportTemplateVersion`, `ExportTemplateAssignment`, `DashboardProfileSettings`, `GenerateReportJob`.

### 6.5.a Sous-workflow IA — Import PTA

1. **Upload** : un utilisateur habilité ouvre `workspace.ai-imports.pta.index` et dépose un fichier PTA (`csv`, `xlsx`, `pdf`, `doc`, `docx`, image). Le fichier est stocké sur le disque local non public.
2. **Analyse** : `PtaExtractionService` extrait les lignes tabulaires quand le format le permet. Pour les PDF, le service tente l'extraction texte native puis l'OCR configure (`AI_PTA_PDF_OCR_COMMAND`) ou l'OCR Windows local integre ; un PDF scanne non lisible est bloque avec un message explicite au lieu de creer une ligne factice.
3. **Normalisation** : `PtaNormalizationService` mappe les colonnes vers les champs PTA attendus, normalise statuts/budgets/dates et crée les lignes `ai_import_rows`.
4. **Prévisualisation** : l'utilisateur consulte les lignes, erreurs, avertissements et scores de confiance avant toute écriture métier.
5. **Correction humaine** : les lignes peuvent être corrigées ou ignorées. Les corrections sont enregistrées dans `corrected_data`.
6. **Validation** : `PtaImportValidationService` bloque les lignes incomplètes ou incohérentes. Les erreurs restent visibles et exportables.
7. **Génération Excel** : le fichier destiné à l'import final contient une seule feuille `IMPORT_GLOBAL`, alignée sur les 40 colonnes du modèle canonique actuel de l'ancien module (`imports-excel/modele`) ; les onglets d'erreurs ou métadonnées ne sont pas ajoutés au classeur importable.
8. **Import final** : `PtaFinalImportService` réexécute la validation, crée au besoin les conteneurs PAS/PAO/PTA, puis crée uniquement les actions issues des lignes valides.
9. **Historique et apprentissage** : chaque étape sensible est tracée dans `ai_import_audits` ; les corrections humaines validées alimentent `ai_training_examples`.

### 6.5.b Sous-workflow IA — Rapports PAS/PAO/PTA

1. **Sélection** : l'utilisateur habilité choisit le type de rapport (`pas`, `pao`, `pta`) et le périmètre (exercice, direction, service).
2. **Calcul métrique** : les builders `PasReportDataBuilder`, `PaoReportDataBuilder`, `PtaReportDataBuilder` s'appuient sur les actions Laravel existantes ; le rapport ne dépend pas d'une réponse IA non vérifiée pour ses chiffres.
3. **Rédaction assistée** : `AiReportWritingService` produit un brouillon structuré avec synthèse, analyse, risques et recommandations. Pour le rapport PTA trimestriel, `PtaQuarterlyNarrativeBuilder` formule aussi des paragraphes analytiques proches du modèle Word fourni : progression globale, lecture des axes, dynamique mensuelle, causes probables des écarts et mesures correctives.
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
- Désignation du chef d'unité (`unites-dg.set-chef`) — `ChefUniteSyncService` verrouille utilisateurs et unités dans une transaction, aligne `users.unite_dg_id` et garantit qu'un utilisateur ne dirige qu'une unité.
- Le compte désigné doit être actif et déjà rattaché à l'unité ou porter le rôle de chef correspondant. Son rôle n'est jamais modifié implicitement.
- Les créations, mises à jour et désactivations d'utilisateurs exécutent la synchronisation du chef avant le commit ; une erreur annule l'ensemble de l'opération.
- Les simulations de fusion et de transfert utilisent des agrégations SQL et affichent les utilisateurs, PTA, actions et justificatifs concernés sans écriture métier.

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

### 7.9 Centre de commandement Super Administration

- `SuperAdminOverviewService` consolide l'état plateforme, les brouillons, les décisions de gouvernance, les diagnostics, les snapshots, les templates et les opérations sensibles récentes.
- `workspace.super-admin.index` expose quatre domaines d'accès : Plateforme, Gouvernance, Pilotage métier, Continuité et sorties. Chaque entrée mène à l'écran de gestion correspondant.
- La file prioritaire remonte la maintenance active, les diagnostics en anomalie, les demandes de suppression, les brouillons, le risque de continuité et les templates non publiés.
- Le rôle `super_admin` est non délégable : `RoleRegistryService` l'exclut des bases et duplications de rôles personnalisés, et `User` ne reconnaît les privilèges Super Admin que depuis le rôle canonique du compte.
- Le mode maintenance exige une confirmation du mot de passe pour son activation ou sa désactivation. Son secret de contournement est aléatoire, renouvelé et jamais persisté dans les paramètres ou l'audit.
- `PlatformSnapshotService` refuse toute restauration de groupe absent du snapshot, y compris lors d'un appel direct au service métier.

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
- Modèle `RetentionRun` — registre persistant des simulations et exécutions web, console ou planificateur.
- Page `workspace.retention.index` : candidats, politiques, exécutions, recherche et registre des archives.
- Exécution manuelle via `workspace.retention.run`, protégée par `retention.manage` et par un verrou de concurrence par périmètre.
- Export CSV filtré via `workspace.retention.export.csv` et téléchargement JSON masqué d'une archive via `workspace.retention.archives.download`.
- Commandes `anbg:retention-run` et `anbg:planning-auto-archive`, enregistrées avec l'origine `console`.

**Règles.** Le journal d'audit n'est jamais purgé ; seules les données opérationnelles peuvent être archivées selon la politique configurée. Une simulation ne modifie aucune donnée. Une exécution concurrente sur le même périmètre est refusée et tracée. Les secrets sont masqués dans les aperçus et téléchargements d'archives.

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
1. Structuration du PAS actif        (Planification)
2. Déclinaison du PAO par direction  (Direction)
3. Déclinaison du PTA par service    (Service)
4. Paramétrage des actions dans PTA  (Service)
5. Passage du PTA en cours
6. Exécution et saisie cumulative    (Agent / RMO)
7. Soumission de l'action            (Agent → Chef de service)
8. Visa chef puis contrôle final     (Chef → Planification/SCIQ)
9. Financement optionnel             (RMO → DAF → DG)
10. Report d'échéance optionnel      (RMO → Chef → Contrôle → Décideur → Application)
11. Calcul automatique statuts/KPI   (services et observers)
12. Alertes et notifications         (acteurs du périmètre)
13. Clôture et archivage des plans   (acteurs habilités)
14. Reporting consolidé              (Planification / DG)
15. Audit immuable                   (transverse à chaque étape)
```

---

## 10. Matrice synthétique des workflows

| Workflow | Acteurs principaux | États clés | Verrou | Audit | Alertes |
|---|---|---|---|---|---|
| PAS | Planification, DG | actif→cloture→archive | archive non modifiable, déverrouillage gouverné | ✓ | `missing_pao_coverage` |
| PAO | Direction, Planification | en_cours/valide→cloture→archive | archive non modifiable | ✓ | `missing_pao_coverage` |
| PTA | Service, Planification/SCIQ | brouillon→en_cours→controle_sciq→cloture→archive | archive non modifiable, déverrouillage gouverné | ✓ | dérive des actions |
| Action — exécution | Service, Agent | non_demarre→en_cours→a_corriger/cloturee/suspendu/annule (+ statut dynamique) | protection du PTA et des états terminaux | ✓ | `action_overdue`, `action_non_demarre` |
| Action — validation hiérarchique | Agent, Chef de service, SCIQ / Planification | non_soumise→soumise_chef→soumise_controle→validee_controle (+ corrections/rejets) | — | ✓ | via `action_log` |
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

1. **Déverrouillage descendant** : une correction d'un plan parent ne revalide pas automatiquement tous les objets enfants ; l'analyse d'impact reste indispensable.
2. **Couverture PAO** : l'alerte `missing_pao_coverage` identifie les manques, mais la création corrective reste une action utilisateur.
3. **Financement vs exécution** : la règle autorisant ou non le démarrage avant la décision DG doit rester explicite dans le paramétrage métier.
4. **Alertes calculées à la volée** : les performances doivent être surveillées au-delà de 5 000 actions ouvertes.
5. **Parcours unifiés** : Imports et Reporting sont unifiés dans la navigation mais leurs espaces classique et IA restent techniquement distincts.

---

## 12. Workflows consolidés au 27 juillet 2026

### 12.1 Suivi d'une action par onglets

La page `actions/{action}/suivi` conserve la synthèse et les étapes en partie haute, puis répartit les opérations détaillées entre sept onglets :

1. **Validation** : saisie, soumission, visa du chef et contrôle Planification/SCIQ.
2. **Fiche** : rattachements PAS-PAO-PTA, responsables, dates, cible, seuils et risques.
3. **Échéances** : création, instruction et application des reports.
4. **Financement** : dossier RMO, instruction DAF, corrections et décision DG.
5. **Discussion** : commentaires et retours de validation.
6. **Justificatifs** : pièces d'exécution et de correction.
7. **Journal** : événements, décisions et alertes.

Un seul panneau détaillé est visible. Les liens directs sont conservés, l'onglet contenant une erreur est prioritaire après une validation refusée et la navigation clavier reste disponible.

### 12.2 Report d'échéance

```text
RMO + pièce
  → Chef de service
  → Planification/SCIQ
  → DG ou Chef Planification
  → Contrôleur habilité applique la date
```

- La demande contient la nouvelle date souhaitée, le motif, la justification et une pièce obligatoire.
- Un acteur peut demander un complément ; le demandeur fournit alors une nouvelle version de la pièce et la demande revient à l'étape concernée.
- L'index distingue la file **À traiter**, les demandes du périmètre et **Mes demandes**.
- Les versions successives des pièces restent téléchargeables selon les droits.
- Aucune date n'est modifiée avant l'action finale `appliquer`.
- L'application finale est réservée au contrôleur après décision favorable complète.

### 12.3 Navigation Imports et Reporting

- La barre latérale affiche une seule entrée **Imports**, mais les parcours classique et IA/OCR restent techniquement distincts.
- L'import classique applique le modèle, l'aperçu, le mapping, la validation et la confirmation.
- L'import IA/OCR applique le chargement, l'extraction, la correction ligne par ligne, la validation humaine et l'import final audité.
- La barre latérale affiche une seule entrée **Reporting**, mais le centre d'exports institutionnels et l'espace de rapports IA conservent leurs pages propres.
- Le centre d'exports produit des fichiers Excel/PDF immédiatement ou en file, avec téléchargement signé.
- Le rapport IA reste un brouillon jusqu'à sa correction et sa validation humaine, puis peut être exporté en Word, PDF ou Excel.

### 12.4 Notifications, audit et rétention

- Notifications et alertes sont regroupées dans un centre unique avec compteurs, lecture individuelle et lecture globale.
- Les validations, corrections, financements et reports alimentent la boîte des acteurs concernés selon leur rôle et leur périmètre.
- Le journal d'audit est append-only, filtrable et exportable ; il conserve l'ancien et le nouvel état lorsqu'ils sont disponibles.
- La rétention propose une simulation sans écriture, un verrou contre les exécutions concurrentes, un registre d'exécution et des exports contrôlés.
- Le journal d'audit est exclu de toute purge opérationnelle.

### 12.5 Import IA avec OpenAI uniquement

```text
Depot du fichier
  -> extraction technique du contenu
  -> analyse structuree OpenAI
  -> controles referentiels Laravel
  -> revue humaine
  -> validation
  -> import final audite
```

- L'extraction locale lit le document mais ne constitue pas une analyse IA.
- Si OpenAI ne repond pas, la session passe en echec et aucune ligne de secours n'est importee.
- Les erreurs de rattachement, date, doublon ou cible restent bloquantes.

### 12.6 Rapport IA conforme au template

```text
Snapshot Laravel
  -> redaction structuree OpenAI
  -> composition selon le template ANBG
  -> controle code/version/empreinte/sections
  -> relecture et validation humaines
  -> export officiel
```

- Toute modification relance le controle et peut remettre le rapport en brouillon.
- Un export exige une conformite de 100 % et une validation humaine.
- Les rapports historiques sans preuve de template restent bloques jusqu'a revalidation.

## 13. Glossaire

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
