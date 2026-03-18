# Maquettes UI / UX
## Application ANBG PAS / PAO / PTA / Actions

- Version: 1.1
- Date: 2026-03-09
- Cible: Blade + Tailwind

## 1. Principes UX

- navigation stable par sidebar modulee
- visibilite immediate des actions a traiter
- coherence entre theme clair et sombre
- lisibilite prioritaire pour formulaires et tableaux
- feedback systematique (success, erreur, statuts, badges)

## 2. Structure De Navigation
Header:

- bouton menu mobile
- titre de page
- recherche rapide
- toggle theme
- cloche notifications
- avatar + role

Sidebar:

- Pilotage: Dashboard, Mon profil, Pilotage, Reporting, Alertes
- Planification: PAS, PAO, PTA
- Execution: Actions
- Gouvernance: Referentiel
- Controle: Journal Audit

Regles:

- badges de notifications par module
- menu adapte au role

## 3. Ecrans Prioritaires
### 3.1 Connexion

- champs identifiant/mot de passe
- message erreur lisible
- branding ANBG

### 3.2 Dashboard

- cartes KPI synthese:
  - actions enregistrees
  - actions validees (direction)
  - PAO
  - PTA
- zone notifications recentes
- graphes statut/volumetrie
- raccourcis metier

### 3.3 Listing PAS / PAO / PTA

- filtres (statut, direction/service, recherche)
- tableau pagine
- actions contextuelles:
  - creer
  - modifier
  - soumettre
  - valider
  - verrouiller
  - retour brouillon

### 3.4 Formulaire PAS

- informations generales
- saisie dynamique des axes
- saisie objectifs par axe
- affectation direction au niveau axe

### 3.5 Formulaire PAO

- PAS parent
- direction
- annee
- objectifs/resultats/indicateurs
- statut

### 3.6 Formulaire PTA

- PAO parent
- direction/service
- description operationnelle
- statut

### 3.7 Listing Actions

- filtres: PTA, statut dynamique, financement, recherche
- colonnes:
  - libelle
  - responsable
  - progression
  - statut dynamique
  - statut validation
  - semaines renseignees

### 3.8 Ecran Suivi Action (critique)

- bloc contexte:
  - PAS / PAO / PTA / direction / service
- bloc details action:
  - cible
  - ressources
  - financement
  - risques
- bloc periodes:
  - formulaire hebdo (agent)
  - depot justificatifs
- bloc cloture:
  - soumission agent
  - review chef
  - review direction
- bloc KPI:
  - delai, performance, conformite, global
- bloc logs/alertes

## 4. Wireframes Textuels
### 4.1 Dashboard
```text
+---------------------------------------------------------------+
| Topbar: [Menu] [Titre] [Recherche] [Theme] [Notif] [Avatar] |
+--------------------+------------------------------------------+
| Sidebar modules    | KPI Cards                                |
| - Dashboard        | [Actions enreg.] [Actions validees]      |
| - Profil           | [PAO] [PTA]                              |
| - Pilotage         |------------------------------------------|
| - Reporting        | Notifications recentes                   |
| - Alertes          | Graphes / tendances                      |
| - PAS / PAO / PTA  |                                          |
| - Actions          |                                          |
+--------------------+------------------------------------------+
```

### 4.2 Suivi Action
```text
+---------------------------------------------------------------+
| Contexte: PAS > PAO > PTA > Action                            |
+---------------------------------------------------------------+
| Bloc details action (cible, ressources, risques, financement) |
+---------------------------------------------------------------+
| Periodes de suivi                                              |
| Semaine | date debut/fin | saisie | justificatifs | progression|
+---------------------------------------------------------------+
| Cloture agent -> review chef -> review direction               |
+---------------------------------------------------------------+
| KPI + Logs + Alertes                                           |
+---------------------------------------------------------------+
```

## 5. Design System Recommande

- palette claire: fond `slate-50`, texte principal `slate-900`
- palette sombre: fond `slate-950`, texte principal `slate-100`
- boutons:
  - primaire: indigo
  - succes: vert
  - avertissement: ambre
  - danger: rouge
- cartes: coins arrondis + ombre legere + bordure subtile
- tableaux: en-tete contrastes + hover ligne

## 6. Accessibilite

- contraste texte/fond >= WCAG AA
- labels visibles pour tous les champs
- etats focus clairs au clavier
- cibles cliquables minimum 40x40
- retours erreurs explicites et localises

## 7. Responsive

- mobile:
  - sidebar en drawer
  - topbar compacte
  - tableaux en overflow horizontal
- desktop:
  - sidebar fixe
  - multi-colonnes formulaires et KPI

## 8. Notes De Mise En Oeuvre

- conserver un style unifie sur toutes les pages de contenu
- appliquer les memes composants de formulaire partout
- synchroniser les regles de contraste avec `data-theme=light/dark`
- prioriser lisibilite sur effets visuels
