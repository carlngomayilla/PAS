# SPECIFICATIONS FONCTIONNELLES - VERSION RENFORCEE
## Application ANBG de suivi PAS / PAO / PTA / Actions

- Version: 1.0 (renforcee)
- Date: 24/02/2026
- Statut: Draft valide pour implementation

---

## 1. Objet

L'application permet de piloter la chaine de planification et d'execution:

- PAS (pluriannuel)
- PAO (annuel par direction)
- PTA (annuel par service)
- Actions (unite operationnelle unique, pas d'activites)

Elle integre:

- suivi hebdomadaire intelligent (generation auto des semaines)
- calcul automatique des statuts et KPI de performance
- alertes et escalade
- gestion des preuves (justificatifs)
- audit immuable des operations

---

## 2. Perimetre

### 2.1 Inclus

- Authentification / profils / RBAC + perimetre direction/service
- Referentiels (Directions, Services, Utilisateurs)
- Gestion PAS / PAO / PTA (workflows et verrouillage)
- Gestion Actions (creation, suivi hebdo, cloture)
- KPI (definition + calculs automatiques)
- Justificatifs (financement / hebdo / final)
- Pilotage, reporting, exports
- Alertes automatiques et audit

### 2.2 Exclusions

- Module activites (supprime: fusionne dans Action)
- Budgetisation complete (prevu/engage/execute)
- Gestion comptable (pas de workflow de paiement)

---

## 3. Profils utilisateurs et droits (RBAC + perimetre)

Principe de perimetre:

- DG / Planification / Admin / Cabinet: visibilite globale (selon role)
- Direction: visibilite sur sa direction
- Service: visibilite sur son service (+ direction)
- Agent: visibilite sur ses actions assignees + saisies hebdo

| Profil | Portee | Capacites cles |
|---|---|---|
| Admin | Globale | Tout + parametrage + audit + supervision technique |
| DG | Globale | Validation/verrouillage, lecture consolidation + alertes critiques |
| Planification | Globale | Structuration PAS/PAO/PTA, consolidation, reporting |
| Direction | Direction | Gestion PAO + supervision PTA/actions de sa direction |
| Service | Direction+Service | Gestion PTA + creation actions + suivi + validation interne (option) |
| Agent | Direction+Service (restreinte) | Saisie hebdo + difficultes + justificatifs + cloture (si autorise) |
| Cabinet | Lecture seule globale | Consultation pilotage/reporting/audit |

Regle cle:

- Agent ne peut pas creer/modifier/supprimer une action (sauf champs de suivi hebdo et cloture selon regles).

---

## 4. Modele fonctionnel

### 4.1 Hierarchie

PAS -> PAO -> PTA -> Action -> ActionWeeks (semaines) -> Justificatifs

### 4.2 Principes valides

- Un PAO appartient a un PAS + une direction + une annee
- Un PTA appartient a un PAO + une direction + un service
- Une Action appartient a un PTA
- Les semaines sont generees automatiquement selon l'intervalle [date_debut, date_fin_prevue]

---

## 5. Modules fonctionnels (specification ecran/feature)

### 5.1 Authentification / Profil

- Connexion / deconnexion
- Profil: nom, email, photo (et mot de passe si comptes locaux)
- Affichage: role + direction/service de rattachement

Securite:

- controle d'acces par middleware RBAC
- journalisation des connexions (audit technique)

---

### 5.2 Referentiels

- CRUD Directions
- CRUD Services (controle service.direction_id)
- CRUD Utilisateurs (direction/service obligatoires si Agent/Service/Direction)

---

### 5.3 PAS

- CRUD PAS
- Workflow: brouillon -> soumis -> valide -> verrouille
- Reouverture (si necessaire): exige motif, journalisation, et revalidation.

---

### 5.4 PAO

- CRUD PAO
- Rattachement: PAS + Direction + Annee
- Workflow identique
- Gestion:

- axes
- objectifs strategiques
- objectifs operationnels

---

### 5.5 PTA

- CRUD PTA
- Rattachement: PAO + Direction + Service
- Workflow identique
- Reouverture avec motif

---

## 5.6 Actions (unite operationnelle)

### 5.6.1 Creation Action (Chef Service / Service / Planification)

Champs obligatoires:

Identification

- id (auto)
- pta_id
- libelle
- responsable_id (agent)

Planification

- date_debut
- date_fin_prevue
- priorite (basse/moyenne/haute)

Ressources

- ressources_requises (liste/texte structure: main d'oeuvre, equipement, etc.)
- financement_requis (bool)

Si financement_requis = true:

- description_financement (obligatoire)
- source_financement (optionnel)
- justificatif_financement (recommande / obligatoire selon parametre)

Cible

- type_cible (quantitative/qualitative) (obligatoire)

Si quantitative:

- unite (ex: dossiers)
- quantite_cible (obligatoire)

Si qualitative:

- resultat_attendu (obligatoire)
- criteres_d_achevement (optionnel mais recommande)

Risques

- risques_potentiels (optionnel)

### 5.6.2 Cloture Action

L'agent (ou le chef de service selon regle) saisit:

- date_fin_reelle
- rapport_final (texte)
- justificatif_final (obligatoire)

---

## 5.7 Suivi hebdomadaire intelligent (ActionWeeks)

### 5.7.1 Generation automatique des semaines

A la creation de l'action:

- generation d'enregistrements ActionWeek numerotes
- periode decoupee en semaines (date_debut -> date_fin_prevue)

Contraintes:

- unique(action_id, numero_semaine)
- week_start <= week_end

### 5.7.2 Saisie hebdomadaire (Agent)

Chaque fin de semaine (ou sur la semaine concernee), l'agent renseigne:

Champs communs (obligatoires)

- est_renseignee = true
- difficultes_observees (obligatoire si risque/retard)
- mesures_correctives (optionnel, recommande)
- justificatif_hebdo (recommande / obligatoire selon parametre)
- saisi_par / saisi_le (auto)

Si action quantitative:

- quantite_realisee (obligatoire)

Si action qualitative:

- avancement_estime (0-100) (obligatoire)
- taches_realisees (texte) (obligatoire)

Regle: une semaine non renseignee declenche alerte.

---

## 5.8 Statuts dynamiques & KPI calcules automatiquement

### 5.8.1 Statuts dynamiques (calcules)

- non_demarre
- en_cours
- en_avance
- en_retard
- acheve_dans_delai
- acheve_hors_delai

### 5.8.2 Bases de calcul

On calcule a chaque saisie hebdo (ou via job planifie):

Progression theorique (%)

- depend du temps ecoule

Progression reelle (%)

- quantitative: (quantite_cumulee / quantite_cible) x 100
- qualitative: avancement_estime (ou moyenne ponderee)

### 5.8.3 Seuils (parametrables)

- seuil_retard: ex 10% de retard de progression
- seuil_alerte_kpi_global: ex 70%

---

## 5.9 Justificatifs (systeme unique recommande)

Categories minimales:

- financement
- hebdomadaire
- final

Regles:

- stockage securise (NAS / storage prive)
- acces selon profil et perimetre
- historisation des depots (audit)

---

## 5.10 Pilotage / Reporting / Alertes

Pilotage

- tableaux de bord par profil (DG, Direction, Service)
- filtres (annee, direction, service, statut, priorite)

Reporting

- exports CSV / PDF
- rapports periodiques (mensuel/trimestriel/annuel) (option)

Alertes (automatiques)

- semaine non renseignee
- progression reelle < progression theorique - seuil
- echeance proche sans avancement suffisant
- KPI global sous seuil
- financement requis sans justificatif (si regle activee)

Escalade (exemple parametrable):

- J+0: Agent
- J+3: Chef Service
- J+7: Direction
- J+15: DG

---

## 5.11 Audit

Journal des operations critiques:

- module
- entite_type + entite_id
- action (create/update/delete/validate/lock)
- ancienne_valeur / nouvelle_valeur
- user_id
- date + IP

---

## 6. Regles de gestion majeures (validees)

- pas d'Activites: Action est l'unite de travail
- Agent: suivi hebdomadaire uniquement (pas CRUD Action)
- unique(action_id, numero_semaine)
- unique(action_id) dans action_kpis
- unique(kpi_id, periode) dans kpi_mesures
- direction_id + service_id obligatoires selon profil
- workflow de validation: toute reouverture necessite motif + audit

---

## 7. Workflow global (end-to-end)

1. Structuration PAS (Planification)
2. Declinaison PAO (Direction/Planification)
3. Declinaison PTA (Service/Planification)
4. Creation Actions (Service)
5. Generation semaines (auto)
6. Saisie hebdo (Agent)
7. Calcul auto statut/KPI (job)
8. Alertes & escalade (job)
9. Reporting (Planification/DG)
10. Audit (systematique)

---

## 8. Donnees de reference (alignees)

- directions, services, users
- pas/pas_axes/pas_objectifs
- paos/pao_axes/pao_obj_strat/pao_obj_op
- ptas
- actions/action_weeks/action_kpis/action_logs
- kpis/kpi_mesures
- justificatifs
- journal_audit

(Note: si justificatifs polymorphe est retenu comme unique source, retirer action_justificatifs pour eviter doublon.)

---

## 9. Interfaces principales (routes UI)

- /login
- /dashboard
- /workspace/pas*
- /workspace/pao*
- /workspace/pta*
- /workspace/actions*
- /workspace/pilotage
- /workspace/reporting
- /workspace/alertes
- /workspace/audit
- /workspace/referentiel/*

---

## 10. Exigences non fonctionnelles

- RBAC + perimetre direction/service
- tracabilite immuable
- integrite SQL (FK + contraintes uniques)
- UI responsive (dark)
- exports CSV/PDF
- performances: listing pagine, filtres indexes

---

## 11. Criteres d'acceptation (testables)

Le systeme est accepte si:

- chaine PAS->PAO->PTA->Action fonctionne (CRUD + rattachements)
- semaines se generent automatiquement a la creation d'une action
- saisie hebdo agent fonctionne et est historisee
- statuts/KPI se recalculent automatiquement apres saisie
- alertes se declenchent selon les regles et escalades
- justificatifs sont stockes et consultables selon droits
- audit log enregistre toutes operations critiques
