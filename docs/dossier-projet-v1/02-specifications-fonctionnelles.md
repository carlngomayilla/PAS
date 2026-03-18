# Specifications Fonctionnelles
## Application ANBG PAS / PAO / PTA / Actions

- Version: 1.1
- Date: 2026-03-09
- Base de reference: implementation actuelle (controllers, services, migrations)

## 1. Vision Fonctionnelle
Chaine metier cible:

- `PAS` (strategique pluriannuel)
- `PAO` (annuel par direction)
- `PTA` (annuel par service)
- `Action` (execution unitaire)
- `ActionWeek` (suivi periodique auto)
- `Validation` (chef service puis direction)

## 2. Roles Et Droits
Matrice de synthese:

| Role | Lecture | Ecriture |
|---|---|---|
| admin | globale | globale |
| dg | globale | globale |
| planification | globale | globale |
| direction | sa direction | PAO/PTA/actions de sa direction |
| service | son service | PTA/actions de son service |
| agent | actions assignees | suivi periodique + soumission cloture |
| cabinet | globale | aucune |

Regles majeures:

- un agent ne peut pas creer/editer/supprimer une action
- un agent ne voit que les actions dont `responsable_id = user.id`
- un service ne voit que les donnees de son `service_id`
- une direction ne voit que les donnees de son `direction_id`

## 3. Modules Fonctionnels
### 3.1 Authentification Et Profil

- connexion/deconnexion
- affichage nom, role, direction, service, photo
- edition des informations de profil
- persistance du theme clair/sombre

### 3.2 Referentiels

- directions: CRUD (admin/global)
- services: CRUD avec contrainte `service.direction_id`
- utilisateurs: CRUD avec role + rattachement direction/service
- agent: champs metier `matricule`, `fonction`, `telephone`

### 3.3 PAS

- CRUD PAS (global writer)
- structuration en axes et objectifs
- affectation direction au niveau axe
- workflow:
  - `brouillon -> soumis -> valide -> verrouille`
  - `soumis/valide -> brouillon` avec motif

### 3.4 PAO

- CRUD PAO (global + direction concernee)
- rattachement obligatoire a un PAS et une direction
- gestion axes / objectifs strategiques / objectifs operationnels
- workflow:
  - `brouillon -> soumis -> valide -> verrouille`
  - retour brouillon avec motif selon droits

### 3.5 PTA

- CRUD PTA (global + direction + service concerne)
- rattachement obligatoire a un PAO et un service
- workflow:
  - `brouillon -> soumis -> valide -> verrouille`
  - retour brouillon avec motif

### 3.6 Actions

Creation/edition reservee a `admin/dg/planification/direction/service`:

- identification: libelle, description
- rattachement: PTA, responsable (agent)
- cible:
  - quantitative: unite + quantite cible
  - qualitative: resultat attendu + criteres + livrable
- planification: date debut, date fin, frequence execution
- risques et mesures preventives
- ressources mobilisees
- financement requis + details + justificatif

#### Frequences supportees

- instantanee
- journaliere
- hebdomadaire
- mensuelle
- annuelle

#### Generation automatique des periodes
A l enregistrement ou lors d un changement date/frequence:

- creation des lignes `action_weeks`
- contrainte `unique(action_id, numero_semaine)`
- regeneration interdite si une periode est deja renseignee

### 3.7 Suivi Agent
Sur chaque periode:

- saisie obligatoire des difficultes et mesures correctives
- depot justificatif obligatoire
- si quantitatif:
  - `quantite_realisee`
- si qualitatif:
  - `taches_realisees`
  - `avancement_estime`

### 3.8 Cloture Et Validation Action
Workflow de validation:

1. Agent soumet l action (`soumise_chef`)
2. Chef de service:
   - valide (`validee_chef`) ou rejette (`rejetee_chef`)
3. Direction:
   - valide (`validee_direction`) ou rejette (`rejetee_direction`)

Regles:

- soumission agent: justificatif final optionnel
- precondition: au moins un justificatif hebdomadaire existe
- gel de saisie agent des que statut passe en validation (`soumise_chef` ou `validee_chef`)
- degel uniquement apres rejet chef ou rejet direction
- action comptabilisee en statistique officielle uniquement si `validee_direction`

## 4. Regles De Calcul
### 4.1 Progressions

- progression theorique:
  - `(temps ecoule / duree totale) * 100`
- progression reelle:
  - quantitatif: `(quantite cumulee / quantite cible) * 100`
  - qualitatif: dernier `avancement_estime`

### 4.2 Statut Dynamique Action
Valeurs:

- `non_demarre`
- `en_cours`
- `en_avance`
- `en_retard`
- `acheve_dans_delai`
- `acheve_hors_delai`

Logique:

- `en_avance` si `progression_reelle >= progression_theorique + 5`
- `en_retard` si echeance depassee sans completion
- completion definitive (`acheve_*`) seulement apres validation direction

### 4.3 KPI Action

- `kpi_delai`
- `kpi_performance`
- `kpi_conformite`
- `kpi_global = 0.4*delai + 0.4*performance + 0.2*conformite`

Alertes:

- ecart progression > `seuil_alerte_progression`
- date proche avec avancement insuffisant
- `kpi_global < 60`
- periode echue non renseignee

Escalade des periodes non renseignees:

- 1ere: responsable
- 2eme: chef_service
- 3eme: direction
- 4eme+: dg

## 5. Notifications
Chaque notification stocke:

- titre, message
- module (`pas`, `pao`, `pta`, `actions`, `reporting`, `alertes`, `audit`)
- `entity_type`, `entity_id`
- URL de redirection
- statut lu/non lu

Affichages:

- cloche header (compteur global)
- badges par module dans sidebar
- listing detaille avec marquage lu

## 6. Dashboard / Pilotage / Reporting
Dashboard:

- volumes PAS/PAO/PTA/actions/KPI/mesures
- `actions_enregistrees`
- `actions_validees` (validation direction)
- repartition statuts et alertes

Pilotage:

- taux completion par module
- trous de pipeline (PAS sans PAO, PAO sans PTA, PTA sans action)

Reporting:

- etat fait/non fait par scope utilisateur
- export CSV et PDF
- scope automatique par role, direction, service

## 7. Regles Transverses

- suppression interdite si parent verrouille
- verrouillage fige l ecriture sur l entite
- toute transition critique ecrit dans `journal_audit`
- pieces justificatives stockees et telechargeables selon droits

## 8. Criteres D Acceptance

1. RBAC et perimetre appliques sur tous les modules.
2. Creation PAS -> PAO -> PTA -> Action operationnelle.
3. Generation de periodes auto selon frequence execution.
4. Un agent ne peut renseigner que ses actions.
5. Soumission/validation action suit strictement le circuit a 2 niveaux.
6. Dashboard et reporting affichent les donnees du scope utilisateur.
7. Notifications visibles en cloche + badges module.
8. Audit present pour create/update/delete/submit/approve/lock/reopen/review.
