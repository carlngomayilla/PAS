# Comparaison des documents Word avec les specifications issues du code

Date : 2026-07-08

## 1. Sources comparees

Documents Word fournis :

- `C:\Users\chris\OK\CSF_ePilotage_ANBG.docx` ;
- `C:\Users\chris\OK\CDC_ePilotage_ANBG.docx`.

Documents generes depuis le code :

- `docs/cahier-specifications-fonctionnelles-application.md` ;
- `docs/cahier-des-charges-application.md`.

Code local analyse :

- routes web et API ;
- modeles ;
- migrations ;
- services ;
- vues Blade ;
- tests fonctionnels ;
- documentation projet existante.

## 2. Synthese de la comparaison

Les documents generes depuis le code couvrent l'ensemble de l'application : PAS, PAO, PTA, actions, indicateurs, suivi PTA, imports, IA, reporting, alertes, audit, administration et API.

Les documents Word sont plus specialises. Ils portent surtout sur la refonte des tableaux de bord e-Pilotage par profil utilisateur, avec :

- navigation PAS / PAO / PTA ;
- tableaux de bord differencies par profil ;
- densite progressive des indicateurs ;
- moteur d'alerte vert / orange / rouge ;
- drill-down vers les vues detaillees ;
- simplification de la saisie agent ;
- workflow de report d'echeance ;
- indicateurs de qualite et de coherence pour planification et suivi-evaluation.

La bonne integration consiste donc a conserver les specifications globales issues du code, puis a enrichir les sections tableaux de bord, alertes, navigation, roles et recette avec les exigences detaillees des Word.

## 3. Points convergents

Les documents Word et le code convergent sur les points suivants :

- pilotage structure autour de PAS, PAO et PTA ;
- importance du suivi PTA ;
- suivi des actions et activites ;
- besoin de tableaux de bord et syntheses ;
- alertes sur retards, ecarts et anomalies ;
- workflow de report d'echeance ;
- roles differencies par perimetre ;
- reporting et exports ;
- audit et tracabilite ;
- controle de qualite des donnees ;
- besoin de vues detaillees.

## 4. Ecarts identifies

### 4.1 Perimetre

Les documents Word couvrent principalement la refonte des tableaux de bord.

Les documents generes depuis le code couvrent toute l'application.

Decision : garder le cahier global issu du code et ajouter un complement specifique "tableaux de bord par profil".

### 4.2 Socle technique

Les documents Word mentionnent Laravel 12, PHP 8.2, PostgreSQL et Filament v3.

Le code local audite indique Laravel 13, PHP 8.4, Blade/Tailwind, Sanctum et services Laravel.

Decision : conserver le socle technique reel du code. Les composants Filament cites dans les Word sont traduits en equivalents fonctionnels de l'application existante : cartes, onglets, tableaux, badges, graphiques, vues detaillees et formulaires Blade/Tailwind.

### 4.3 Vocabulaire "cible"

Les documents Word utilisent le mot "cible".

La consigne metier est de retirer ce mot de l'application, en particulier dans le controle PTA.

Decision : remplacer dans les specifications applicatives par :

- "seuil" pour le niveau de controle ;
- "quantite a realiser" pour les indicateurs quantitatifs ;
- "livrables attendus" pour les indicateurs non quantitatifs ;
- "niveau attendu" ou "objectif 2028" pour la vision strategique.

### 4.4 Profils

Les documents Word decrivent six profils principaux :

- Direction generale ;
- Directeur ;
- Chef de service ;
- Agent ;
- Chef de service planification ;
- Gestionnaire suivi-evaluation.

Le code contient davantage de profils et permissions, notamment planification, SCIQ, suivi global, super-administration et roles de gouvernance.

Decision : conserver la richesse des roles du code et mapper les profils Word vers les profils existants.

### 4.5 Interface

Les documents Word recommandent des cartes, onglets, tables, infolists, sliders et badges.

Le code contient deja des vues, tableaux, suivi PTA, syntheses, graphiques et vues detaillees.

Decision : ne rien supprimer. Ajouter ou adapter les composants dans les ecrans existants.

## 5. Exigences Word retenues

Les exigences suivantes ont ete reintegrees dans les documents Markdown.

### 5.1 Navigation progressive

La navigation doit suivre les niveaux :

- PAS ;
- PAO ;
- PTA ;
- directions ;
- services ;
- actions.

Un fil d'Ariane doit permettre le retour au niveau superieur.

### 5.2 Densite progressive

Chaque tableau de bord d'accueil doit afficher 4 a 6 indicateurs cles maximum.

Les donnees complementaires doivent rester disponibles dans :

- onglets ;
- sections repliables ;
- graphiques ;
- vues detaillees ;
- pages de suivi.

### 5.3 Tableaux de bord par profil

Les tableaux de bord doivent etre adaptes a chaque profil :

- Direction generale : vision consolidee PAS / PAO / PTA ;
- Directeur : pilotage de la direction ;
- Chef de service : pilotage du service et agents ;
- Agent : activites assignees et saisie simplifiee ;
- Planification : coherence PAS / PAO / PTA et reporting ;
- Suivi-evaluation / SCIQ : qualite et fiabilite de la donnee.

### 5.4 Moteur d'alerte

Le moteur d'alerte doit gerer :

- critique ;
- vigilance ;
- conforme ;
- information.

Chaque alerte doit avoir une couleur, mais aussi un libelle ou une icone.

### 5.5 Workflow de report

Le workflow de report doit couvrir :

- demande ;
- motif obligatoire ;
- une seule demande active par activite ;
- validation ou rejet ;
- mise a jour automatique de l'echeance ;
- historique ;
- signalement du nombre de reports successifs.

### 5.6 Qualite de la donnee

Les profils planification, SCIQ et suivi-evaluation doivent disposer d'indicateurs sur :

- activites sans mise a jour ;
- indicateurs sans source de collecte ;
- activites a 100% sans justificatif ;
- incoherences de rattachement PTA / PAO ;
- modifications posterieures suspectes.

## 6. Elements a conserver imperativement

Conformement a la consigne utilisateur, l'integration ne doit pas supprimer :

- le design actuel ;
- la page de suivi du PTA ;
- les syntheses ;
- les graphiques ;
- les vues detaillees ;
- la fiche PTA ;
- les exports existants ;
- les boutons utiles aux profils planification, SCIQ et responsables habilites.

## 7. Documents modifies

Les documents suivants ont ete enrichis :

- `docs/cahier-specifications-fonctionnelles-application.md` :
  - ajout d'un complement d'integration des documents Word ;
  - ajout de la navigation progressive PAS / PAO / PTA ;
  - ajout du moteur d'alerte ;
  - ajout des tableaux de bord par profil ;
  - ajout de la matrice de permissions complementaire ;
  - ajout des exigences de conservation des vues existantes.

- `docs/cahier-des-charges-application.md` :
  - ajout de l'orientation projet retenue ;
  - ajout des principes directeurs ;
  - ajout des exigences par profil ;
  - ajout des exigences sur alertes et donnees ;
  - ajout des contraintes de compatibilite avec le code actuel ;
  - ajout des priorites d'implementation.

## 8. Recommandation d'implementation

L'implementation doit se faire par evolution progressive :

1. Stabiliser le suivi PTA et les libelles indicateurs.
2. Conserver les vues existantes et les rendre plus rapides a utiliser.
3. Ajouter les cartes de synthese par profil.
4. Ajouter le drill-down PAS / PAO / PTA avec fil d'Ariane.
5. Parametrer le moteur d'alerte.
6. Ajouter les indicateurs de qualite de donnee.
7. Optimiser les tableaux de bord avec cache ou agregats si necessaire.

Cette approche evite de casser ce qui fonctionne deja tout en integrant les exigences metier des documents Word.
