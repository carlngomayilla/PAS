# RAPPORT DES SPECIFICATIONS FONCTIONNELLES
## Application ANBG de pilotage PAS / PAO / PTA / Actions

- Version: etat actuel de l'application
- Date: 11/03/2026
- Statut: aligne sur les fonctionnalites effectivement implementees

---

## 1. Objet de l'application

L'application permet de piloter la chaine de planification institutionnelle et l'execution operationnelle de l'ANBG selon la structure suivante:

- PAS: Plan d'Actions Strategique pluriannuel
- PAO: Plan d'Actions Operationnel annuel par direction
- PTA: Plan de Travail Annuel par service
- Actions: unite operationnelle unique d'execution

L'application ne gere pas de module "Activites" distinct: l'action constitue l'unite de travail de reference.

L'application n'est pas un logiciel budgetaire. Elle gere uniquement:

- la mention du financement requis
- la description du besoin
- la source de financement
- le montant estimatif
- les justificatifs associes

---

## 2. Perimetre fonctionnel

### 2.1 Fonctionnalites incluses

- authentification des utilisateurs
- gestion des profils et du perimetre de visibilite
- gestion des directions, services et utilisateurs
- creation et gestion du PAS
- definition des axes strategiques du PAS
- definition des objectifs strategiques du PAS
- rattachement des axes du PAS aux directions concernees
- creation et gestion du PAO par direction
- creation et gestion du PTA par service
- creation, affectation et pilotage des actions
- generation automatique des periodes de suivi
- saisie de suivi par l'agent executeur
- gestion des justificatifs
- circuit de validation hierarchique des actions
- calcul automatique des progressions, statuts et KPI
- alertes operationnelles
- reporting et exports
- notifications internes
- journal d'audit
- gestion des delegations temporaires
- gouvernance de retention / archivage
- documentation API via Swagger UI

### 2.2 Exclusions

- pas de module "Activites"
- pas de gestion comptable
- pas de budget complet engage / execute / paiements
- pas de module autonome de saisie KPI par les utilisateurs metier dans le menu principal

Les KPI sont utilises en arriere-plan pour:

- les calculs de performance
- les alertes
- les tableaux de bord
- les graphiques
- le reporting

---

## 3. Architecture fonctionnelle

La structure fonctionnelle de l'application est la suivante:

PAS -> Axes strategiques -> Objectifs strategiques -> PAO -> PTA -> Actions -> Periodes de suivi -> Justificatifs / Logs / Notifications

### 3.1 Regles de rattachement

- un PAS couvre une periode pluriannuelle
- un axe strategique appartient a un PAS
- un axe strategique est affecte a une direction
- un axe peut contenir plusieurs objectifs strategiques
- un PAO appartient a un PAS, a une direction et a une annee
- un PTA appartient a un PAO, a une direction et a un service
- une action appartient a un PTA
- une action est attribuee a un agent responsable

---

## 4. Roles utilisateurs et perimetres

### 4.1 Roles metier

- `admin`
- `dg`
- `planification`
- `direction`
- `service`
- `agent`
- `cabinet`

### 4.2 Regles de portee

- `admin`, `dg`, `planification`, `cabinet`: portee globale
- `direction`: voit uniquement sa direction
- `service`: voit uniquement sa direction et son service
- `agent`: voit uniquement sa direction, son service et les actions qui lui sont attribuees

### 4.3 Capacites par role

#### Admin

- administration globale de l'application
- gestion des referentiels
- gestion PAS / PAO / PTA
- creation et gestion des actions
- acces audit, retention, delegations, documentation API
- supervision globale du pilotage

#### DG

- lecture globale
- validation et verrouillage strategiques
- consultation des reportings consolides
- supervision generale

#### Planification

- structuration du PAS
- creation et mise a jour PAS / PAO / PTA
- consolidation des donnees
- production des reportings

#### Direction

- gestion du PAO de sa direction
- supervision des PTA et actions de sa direction
- validation finale des actions apres evaluation du chef de service
- consultation pilotage / reporting / alertes dans son perimetre

#### Service

- gestion du PTA du service
- creation, modification et suppression des actions du service
- affectation des actions aux agents
- evaluation des actions soumises par les agents
- validation ou rejet au niveau chef de service

#### Agent

- consultation de ses actions attribuees
- saisie des suivis periodiques
- televersement des justificatifs d'execution
- declaration des difficultes et mesures correctives
- soumission de cloture au chef de service

L'agent ne peut pas:

- creer une action
- modifier la structure d'une action
- supprimer une action
- valider une action

#### Cabinet

- consultation globale en lecture seule
- acces au pilotage, reporting et audit

---

## 5. Modules fonctionnels

## 5.1 Authentification et profil

Le module permet:

- connexion par email
- connexion par matricule
- deconnexion
- consultation et mise a jour du profil
- affichage photo, nom, role, direction, service
- gestion des sessions actives
- revocation des autres sessions

## 5.2 Referentiels

Le module permet:

- gestion des directions
- gestion des services
- gestion des utilisateurs
- rattachement d'un service a une direction
- rattachement d'un utilisateur a une direction et/ou un service
- definition du role de l'utilisateur

## 5.3 Module PAS

Le module PAS permet:

- creation d'un PAS
- modification d'un PAS
- suppression d'un PAS si autorisee
- creation des axes strategiques
- affectation d'une direction par axe strategique
- creation des objectifs strategiques rattaches aux axes
- soumission du PAS
- validation du PAS
- verrouillage du PAS
- reouverture du PAS avec motif

## 5.4 Module PAO

Le module PAO permet:

- creation d'un PAO annuel rattache a un PAS
- rattachement du PAO a une direction
- saisie des objectifs operationnels
- suivi du statut du PAO
- soumission, validation, verrouillage et reouverture avec motif

## 5.5 Module PTA

Le module PTA permet:

- creation d'un PTA rattache a un PAO
- rattachement du PTA a une direction et un service
- suivi du statut du PTA
- soumission, validation, verrouillage et reouverture avec motif

## 5.6 Module Actions

Le module Actions constitue le coeur operationnel de l'application.

Il permet:

- creation d'une action par un profil non agent
- affectation de l'action a un agent
- definition des informations de pilotage
- generation automatique des periodes de suivi
- execution et suivi par l'agent
- cloture avec circuit de validation

### Champs fonctionnels de l'action

#### Identification

- libelle
- description
- responsable principal
- priorite

#### Planification

- date debut
- date fin prevue
- date echeance
- frequence d'execution:
  - instantanee
  - journaliere
  - hebdomadaire
  - mensuelle
  - annuelle

#### Cible

- type de cible:
  - quantitative
  - qualitative

Si cible quantitative:

- unite cible
- quantite cible

Si cible qualitative:

- resultat attendu
- criteres de validation
- livrable attendu

#### Ressources

- main d'oeuvre
- equipement specialise
- partenariat
- autres ressources
- details complementaires

#### Financement

- financement requis
- description du besoin
- source de financement
- montant estime

#### Risques

- risques potentiels
- mesures preventives

## 5.7 Generation automatique des periodes

Lors de la creation ou regeneration d'une action, le systeme genere automatiquement les periodes de suivi en fonction:

- de la date debut
- de la date fin
- de la frequence d'execution

Le systeme cree des periodes numerotees avec:

- date debut
- date fin
- progression theorique
- progression reelle
- etat renseigne / non renseigne

## 5.8 Suivi d'execution par l'agent

L'agent renseigne ses periodes d'execution.

Selon le type de cible, il peut saisir:

### Action quantitative

- quantite realisee
- commentaire
- difficultes rencontrees
- mesures correctives
- justificatif

### Action qualitative

- taches realisees
- avancement estime (%)
- difficultes rencontrees
- mesures correctives
- justificatif

## 5.9 Gel des saisies

Quand l'agent soumet son action au chef de service:

- les champs de saisie agent sont figes
- aucune modification n'est possible tant que l'action est en cours de validation
- la saisie redevient modifiable uniquement si l'action est rejetee avec motif

## 5.10 Cloture et validation des actions

Le circuit de validation est:

Agent -> Chef de service -> Direction

### Etape 1: soumission agent

L'agent renseigne:

- date fin reelle
- rapport final

Il n'a pas a ajouter un justificatif final supplementaire si les justificatifs ont deja ete fournis pendant l'execution.

### Etape 2: evaluation chef de service

Le chef de service:

- consulte l'action
- consulte les notes, periodes et justificatifs
- attribue une note sur 100
- ajoute un commentaire
- valide ou rejette

### Etape 3: validation direction

La direction:

- consulte l'action apres validation chef
- attribue une note sur 100
- ajoute un commentaire
- valide ou rejette

Une action n'est comptabilisee dans les statistiques officielles qu'apres validation direction.

## 5.11 Discussion et retours

Chaque action dispose d'un fil de discussion permettant:

- commentaires libres
- traces des validations
- traces des rejets
- historique des retours chef et direction

## 5.12 Justificatifs

Le module de justificatifs permet:

- depot de justificatifs hebdomadaires / periodiques
- depot de justificatifs de financement
- depot de justificatifs d'evaluation
- telechargement securise
- rattachement a l'action ou a la periode concernee

## 5.13 Notifications

Le systeme notifie les utilisateurs lors des evenements majeurs:

- action attribuee a un agent
- action soumise au chef
- action validee ou rejetee par le chef
- action validee ou rejetee par la direction
- PAS / PAO / PTA soumis, valides, verrouilles ou reouverts

Les notifications sont visibles:

- dans la cloche du header
- via les badges par module dans la sidebar
- dans le dashboard

## 5.14 Alertes

Le module Alertes permet de visualiser:

- les actions en retard
- les KPI sous seuil
- les alertes hebdomadaires et risques

Chaque alerte permet d'acceder directement a sa cause:

- vers la page de suivi de l'action
- vers la section d'etat d'avancement
- vers la periode concernee
- vers le journal d'alertes de l'action

## 5.15 Dashboard et pilotage

Le dashboard affiche notamment:

- le nombre d'actions enregistrees
- le nombre d'actions validees
- les volumes par module
- la repartition des alertes
- les statuts par module
- les notifications recentes
- les modules accessibles pour le profil connecte

Le module Pilotage permet:

- une vue consolidee du PAS
- la synthese des volumes par niveau
- l'avancement global
- l'analyse des axes et objectifs
- la comparaison interannuelle

## 5.16 Reporting

Le module Reporting permet:

- affichage de tableaux et graphiques
- reporting sur PAS / PAO / PTA / Actions
- comparaison interannuelle
- vue consolidee du PAS
- export PDF
- export tableur

Le reporting est scope selon le role:

- une direction voit sa direction
- un service voit son service
- les profils globaux voient tout

## 5.17 Audit

Le module Audit permet:

- consultation du journal des operations
- filtrage par module, action, utilisateur
- tracabilite des changements critiques

## 5.18 Delegations

Le module Delegations permet:

- designation temporaire d'un delegue
- limitation a une direction ou un service
- attribution de permissions de lecture/ecriture/validation
- annulation d'une delegation

Ce module sert a assurer la continuite des validations en cas d'absence.

## 5.19 Retention et archivage

Le module Retention permet:

- visualisation des regles de retention
- simulation ou execution d'une campagne d'archivage
- creation de snapshots d'archives

## 5.20 Documentation API

Le module Documentation API permet:

- consultation du contrat OpenAPI
- navigation Swagger UI
- support des integrations futures

---

## 6. Regles de gestion principales

### 6.1 Regles de visibilite

- un agent ne voit que les actions qui lui sont attribuees
- un service ne voit que les donnees de son service et de sa direction
- une direction ne voit que les donnees de sa direction
- les profils globaux voient l'ensemble
- les delegations peuvent etendre temporairement la portee

### 6.2 Regles de workflow PAS / PAO / PTA

Statuts metier:

- brouillon
- soumis
- valide
- verrouille

Regles:

- une reouverture exige un motif
- un element verrouille ne peut plus etre modifie

### 6.3 Regles de workflow Action

Statuts de validation:

- non soumise
- soumise au chef
- rejetee par le chef
- validee par le chef
- rejetee par la direction
- validee par la direction

Statuts dynamiques:

- non demarre
- en cours
- en avance
- en retard
- acheve dans delai
- acheve hors delai

### 6.4 Regles de calcul

Le systeme calcule automatiquement:

- progression theorique
- progression reelle
- KPI delai
- KPI performance
- KPI conformite
- KPI global
- statut dynamique

### 6.5 Regles de preuve

- une action soumise doit etre justifiee par les preuves d'execution deja deposees
- les justificatifs sont consultables selon les droits de l'utilisateur

### 6.6 Regles d'alerte

Une alerte peut etre produite en cas de:

- retard sur echeance
- progression insuffisante
- KPI sous seuil
- evenement critique ou warning dans le journal d'action

---

## 7. Interface utilisateur

L'application fournit:

- une page de connexion
- un dashboard personnalise
- une sidebar dynamique selon le role
- un mode clair / sombre
- des pages de contenu uniformisees
- des notifications visibles dans le header et le dashboard

---

## 8. Exigences fonctionnelles majeures

L'application doit permettre:

- l'alignement PAS -> PAO -> PTA -> Action
- la ventilation strategique par direction des axes du PAS
- le partage automatique du cadre strategique vers les directions concernees
- le suivi periodique de l'execution reelle
- la validation hierarchique des actions
- la production de statistiques fiables apres validation direction
- la tracabilite des operations et decisions

---

## 9. Etat fonctionnel actuel

La version actuelle couvre les besoins suivants:

- gouvernance strategique PAS / PAO / PTA
- execution des actions
- suivi periodique intelligent
- circuit de validation agent / chef / direction
- alertes avec acces direct a la cause
- notifications internes
- reporting et pilotage
- audit
- delegation temporaire
- retention
- documentation API

Les fonctions non retenues dans le produit sont:

- module Activites
- gestion budgetaire complete
- module scolaire / gestion ecole
- module autonome de saisie KPI dans le menu utilisateur

---

## 10. Conclusion

L'application ANBG est un systeme de pilotage strategique et operationnel centre sur:

- la structuration de la planification
- l'execution des actions
- la responsabilisation des agents
- la validation hierarchique
- la mesure de performance
- la tracabilite decisionnelle

Elle est adaptee a un usage de gouvernance interne, de suivi de plans d'action et de reporting institutionnel.
