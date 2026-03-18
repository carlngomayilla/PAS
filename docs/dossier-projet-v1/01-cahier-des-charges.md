# Cahier Des Charges
## Application ANBG De Pilotage PAS / PAO / PTA / Actions

- Version: 1.1
- Date: 2026-03-09
- Statut: Pret pour execution

## 1. Presentation Du Projet
L application ANBG permet de piloter la planification strategique et l execution operationnelle sur une chaine unifiee:

- PAS (plan pluriannuel)
- PAO (plan annuel par direction)
- PTA (plan annuel par service)
- Actions (execution, suivi periodique, validation)

Le systeme remplace un suivi manuel fragmente et apporte une tracabilite complete des decisions et des realisations.

## 2. Contexte Et Probleme
Constats sur le dispositif initial:

- donnees dispersees dans plusieurs supports
- difficultes de consolidation par direction/service
- faible tracabilite des validations
- suivi de progression heterogene
- faible capacite de reporting fiable

Le besoin est de disposer d une application unique, auditable, avec filtrage strict par role, direction et service.

## 3. Objectifs Du Produit
Objectifs metier:

- aligner toute action operationnelle sur PAS -> PAO -> PTA
- imposer un suivi periodique de l execution des actions
- fiabiliser la validation hierarchique (agent -> chef service -> direction)
- produire des tableaux de bord et rapports exploitables
- tracer toutes les operations critiques

Objectifs techniques:

- securiser les acces par RBAC et perimetre organisationnel
- automatiser les calculs de progression, statut et KPI
- centraliser les justificatifs et notifications
- fournir des exports CSV/PDF

## 4. Utilisateurs Cibles
Roles pris en charge:

- `admin`
- `dg`
- `planification`
- `direction`
- `service`
- `agent`
- `cabinet`

Perimetre:

- global: `admin`, `dg`, `planification`, `cabinet` (lecture seule pour cabinet)
- direction: `direction`
- direction + service: `service`
- actions assignees uniquement: `agent`

## 5. Perimetre Fonctionnel
Inclus:

- authentification et profil
- referentiels (directions, services, utilisateurs)
- gestion PAS, PAO, PTA avec workflow et verrouillage
- gestion des actions (creation, edition, affectation agent)
- generation automatique des periodes d execution
- suivi agent et depot de justificatifs
- validation en 2 niveaux (chef de service puis direction)
- dashboard, pilotage, reporting, alertes
- notifications applicatives et journal audit

Exclus:

- module activites (supprime)
- module budgetaire complet (comptabilite/paiement)
- module "gestion ecole" dans le parcours metier courant

## 6. Exigences Fonctionnelles Prioritaires
Niveau 1 (obligatoire):

- connexion et controle des droits par role
- creation PAS, PAO, PTA et actions selon perimetre
- suivi periodique agent avec pieces justificatives
- soumission cloture action et double validation
- dashboard avec `actions enregistrees` et `actions validees`
- reporting scope direction/service
- notifications (cloche, badges module, marquage lu)

Niveau 2 (important):

- exports reporting CSV/PDF
- alertes automatiques (retard, sous seuil, periode non renseignee)
- historique complet des operations

## 7. Contraintes
Contraintes organisationnelles:

- PAO rattache a une direction unique
- une direction possede plusieurs services
- un axe strategique peut porter plusieurs objectifs strategiques
- l affectation direction doit etre definie au niveau axe PAS

Contraintes techniques:

- stack Laravel 12 / PHP 8.2
- interface Blade + Tailwind v4
- base relationnelle SQL
- stockage local securise des justificatifs

Contraintes de securite:

- authentification obligatoire
- autorisation serveur sur chaque endpoint
- isolation stricte des donnees par perimetre
- journal audit non destructif des actions critiques

## 8. Planning Macro
Phase 1:

- cadrage fonctionnel et modelisation
- validation des workflows et regles de gestion

Phase 2:

- implementation modules coeur (PAS/PAO/PTA/Actions)
- implementation validation et notifications

Phase 3:

- dashboard, pilotage, reporting, exports
- stabilisation UX light/dark

Phase 4:

- tests fonctionnels et securite
- preproduction et deploiement

## 9. Livrables Attendus

- cahier des charges
- specifications fonctionnelles
- specifications techniques
- UML (use case, classes, sequence, activite/etat)
- MCD/MLD et dictionnaire de donnees
- maquettes UI/UX
- plan de test et PV de recette (phase QA)

## 10. Criteres De Reussite

- chaque role voit uniquement les donnees autorisees
- un agent ne voit que ses actions assignees
- une action validee direction est comptee en statistique officielle
- reporting renvoie l etat fait/non fait sur PAS, PAO, PTA, Actions dans le scope utilisateur
- toutes les transitions de statut sont historisees
