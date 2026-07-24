# Cahier de specifications fonctionnelles

Application ANBG e-Pilotage PAS / PAO / PTA

Date de reference : 2026-07-08

Source d'analyse : code Laravel local, routes web et API, modeles, migrations, services applicatifs, tests fonctionnels et documentation existante.

## 1. Objet du document

Ce document decrit les specifications fonctionnelles de l'application de pilotage de l'ANBG telle qu'elle est implementee dans le code.

Il sert a :

- decrire les modules disponibles ;
- preciser les acteurs et leurs responsabilites ;
- formaliser les workflows metier ;
- identifier les donnees manipulees ;
- definir les regles de gestion visibles dans l'application ;
- fournir une base de recette fonctionnelle et d'evolution.

## 2. Presentation generale

L'application est une plateforme web de pilotage strategique et operationnel. Elle permet de structurer la chaine de planification et de suivi autour de trois niveaux principaux :

- PAS : Plan d'Actions Strategique ;
- PAO : Plan d'Actions Operationnel ;
- PTA : Plan de Travail Annuel.

Elle couvre egalement le suivi d'execution des actions, la mesure des indicateurs, le controle du PTA, les imports de donnees, les exports, les alertes, la gouvernance, l'audit et l'administration de la plateforme.

L'application est construite en Laravel avec une interface web Blade/Tailwind et une API versionnee protegee par Sanctum.

## 3. Perimetre fonctionnel global

Le perimetre fonctionnel identifie dans le code comprend :

- authentification, profil utilisateur et securite de session ;
- tableaux de bord et syntheses ;
- gestion des referentiels administratifs ;
- gestion du PAS ;
- gestion du PAO ;
- gestion du PTA ;
- controle et suivi officiel du PTA ;
- gestion des actions, sous-actions, responsables et echeances ;
- gestion des indicateurs et mesures KPI ;
- gestion des justificatifs ;
- demandes de suppression et de report d'echeance ;
- gestion des financements ;
- imports Excel et imports assistes par IA ;
- generation de rapports IA ;
- reporting, exports PDF/Excel/Word et tableaux officiels ;
- alertes, notifications et taches personnelles ;
- gouvernance, audit et tracabilite ;
- super-administration de la plateforme ;
- API REST versionnee.

## 4. Acteurs et profils

Les profils exacts dependent du parametrage applicatif, mais le code fait apparaitre les familles d'acteurs suivantes.

### 4.1 Super-administrateur

Responsable du parametrage global de la plateforme.

Fonctions principales :

- gerer les utilisateurs, roles et permissions ;
- gerer les parametres de plateforme ;
- administrer les modules ;
- gerer les exercices ;
- consulter les journaux et diagnostics ;
- configurer les workflows, alertes, notifications et modeles d'export ;
- administrer les referentiels globaux.

### 4.2 Administrateur et administrateur fonctionnel

Responsables de l'exploitation courante de l'application.

Fonctions principales :

- maintenir les referentiels ;
- accompagner les directions et services ;
- superviser les donnees de planification ;
- suivre les imports et les corrections ;
- acceder aux tableaux de bord et exports selon droits.

### 4.3 Direction generale, cabinet et gouvernance

Profils de supervision institutionnelle.

Fonctions principales :

- consulter les syntheses globales ;
- suivre l'execution du PAS, PAO et PTA ;
- arbitrer ou valider certaines demandes ;
- acceder aux rapports officiels ;
- consulter les alertes et indicateurs de performance.

### 4.4 Direction

Profil de pilotage au niveau direction.

Fonctions principales :

- consulter les plans et actions de son perimetre ;
- suivre les actions rattachees a ses services ;
- analyser les indicateurs et alertes ;
- participer aux validations selon le workflow configure.

### 4.5 Service et chef de service

Profil operationnel de saisie, execution et suivi.

Fonctions principales :

- creer ou renseigner les actions autorisees ;
- renseigner l'avancement ;
- associer des responsables ;
- deposer les justificatifs ;
- demander une suppression ou un report ;
- suivre ses taches et alertes.

### 4.6 Planification et chef planification

Profil charge de la qualite de planification et du controle PTA.

Fonctions principales :

- parametrer et ajuster les donnees de planification ;
- modifier les cellules autorisees dans le tableau de controle PTA ;
- controler les actions, sous-actions, indicateurs, RMO, seuils et echeances ;
- exporter les tableaux ;
- acceder a la fiche PTA d'une action pour modification complete.

### 4.7 SCIQ, suivi global et chef d'unite SCIQ

Profil charge du suivi, du controle et de la qualite des informations.

Fonctions principales :

- controler l'execution ;
- consulter les donnees consolidees ;
- ajuster les informations de suivi autorisees ;
- suivre les anomalies ;
- produire ou consulter des rapports de controle.

### 4.8 Auditeur, invite ou lecteur

Profil a acces limite.

Fonctions principales :

- consulter les donnees autorisees ;
- acceder a certains rapports ou journaux selon permissions ;
- ne pas modifier les donnees hors habilitation.

## 5. Referentiels et donnees maitresses

L'application s'appuie sur des referentiels administrables.

### 5.1 Directions

Les directions representent les entites de rattachement principales.

Donnees gerees :

- nom ;
- code ou sigle ;
- statut ;
- rattachements aux services ;
- responsables associes.

### 5.2 Services

Les services structurent le niveau operationnel.

Donnees gerees :

- nom du service ;
- direction de rattachement ;
- objectifs operationnels ;
- utilisateurs rattaches ;
- actions et sous-actions associees.

### 5.3 Unites DG

Les unites DG permettent de representer certaines entites de pilotage ou de gouvernance.

### 5.4 Utilisateurs

Les utilisateurs possedent :

- informations d'identite ;
- email et identifiants ;
- role principal ;
- permissions ;
- direction, service ou unite de rattachement ;
- historique de mots de passe ;
- eventuelles delegations.

### 5.5 Exercices

Les exercices permettent d'isoler les donnees par periode annuelle ou cycle de planification.

Fonctions associees :

- parametrage des exercices ;
- selection de l'exercice actif ;
- filtrage des donnees ;
- archivage ou consultation historique.

### 5.6 Objectifs operationnels

Les objectifs operationnels structurent les filtres et regroupements du tableau de controle PTA, notamment au niveau du service consulte.

## 6. Authentification et securite utilisateur

### 6.1 Connexion

L'application propose une authentification par email et mot de passe.

Fonctions :

- affichage du formulaire de connexion ;
- verification des identifiants ;
- ouverture de session ;
- redirection vers le tableau de bord ou l'espace autorise ;
- protection des routes par middleware.

### 6.2 Deconnexion

L'utilisateur peut fermer sa session. L'application invalide la session active.

### 6.3 Mot de passe oublie

Le code contient les routes de demande de reinitialisation, d'affichage du formulaire et de mise a jour du mot de passe.

### 6.4 Profil

L'utilisateur peut acceder a son profil pour consulter et modifier les informations autorisees.

### 6.5 Regles transverses de securite

Le code comporte des services et tests de securite portant notamment sur :

- roles et permissions ;
- politiques d'acces ;
- journalisation ;
- sessions ;
- durcissement des requetes ;
- historiques de mots de passe ;
- delegations ;
- perimetres d'acces.

## 7. Tableaux de bord et espace de travail

### 7.1 Tableau de bord principal

Le tableau de bord presente les indicateurs de pilotage selon le profil connecte.

Informations attendues :

- statistiques globales ou par perimetre ;
- taux d'avancement ;
- etat des actions ;
- alertes ;
- actions prioritaires ;
- donnees recentes.

### 7.2 Synthese

La synthese consolide les informations de pilotage.

Fonctions :

- visualisation de l'execution ;
- filtrage selon perimetre ;
- lecture des indicateurs ;
- acces aux elements de detail autorises.

### 7.3 Espace de travail

L'espace de travail centralise les vues operationnelles de l'utilisateur.

Fonctions identifiees :

- consultation des actions ;
- acces au suivi PTA ;
- consultation des taches ;
- acces aux notifications ;
- acces aux recherches et raccourcis metier.

### 7.4 Recherche globale

L'application propose une recherche transverse permettant de retrouver des elements de planification, d'action ou de suivi selon les droits.

## 8. Module PAS

### 8.1 Objectif

Le module PAS structure le niveau strategique.

### 8.2 Donnees principales

- intitule du PAS ;
- periode ou exercice ;
- statut ;
- axes strategiques ;
- objectifs strategiques ;
- rattachements aux niveaux operationnels ;
- informations de validation.

### 8.3 Fonctions

- creer un PAS ;
- modifier un PAS ;
- consulter un PAS ;
- supprimer un PAS si autorise ;
- gerer les axes ;
- gerer les objectifs ;
- suivre le statut ;
- exporter ou consolider les donnees selon les fonctions de reporting.

### 8.4 Regles

- Le PAS sert de base de rattachement au PAO.
- Les modifications dependent des roles et du statut du plan.
- Les operations sensibles sont journalisees.

## 9. Module PAO

### 9.1 Objectif

Le module PAO traduit le niveau strategique en plan operationnel.

### 9.2 Donnees principales

- intitule ;
- exercice ;
- direction ou perimetre ;
- axes PAO ;
- objectifs operationnels ;
- rattachement au PAS ;
- statut ;
- informations de validation.

### 9.3 Fonctions

- creer un PAO ;
- modifier un PAO ;
- consulter un PAO ;
- supprimer un PAO si autorise ;
- gerer les axes et objectifs ;
- suivre le statut ;
- alimenter le PTA.

### 9.4 Regles

- Le PAO est rattache au PAS.
- Les objectifs PAO alimentent les PTA.
- La consultation et la modification sont limitees par le perimetre de l'utilisateur.

## 10. Module PTA

### 10.1 Objectif

Le PTA detaille les actions annuelles a realiser.

### 10.2 Donnees principales

- intitule du PTA ;
- exercice ;
- service ou direction ;
- objectif operationnel ;
- actions ;
- sous-actions ;
- indicateurs ;
- responsables ;
- echeances ;
- statut de validation ;
- donnees d'execution.

### 10.3 Fonctions

- creer un PTA ;
- modifier un PTA ;
- consulter la fiche PTA ;
- gerer les actions rattachees ;
- suivre le statut ;
- verrouiller ou deverrouiller selon workflow ;
- exporter les informations.

### 10.4 Fiche PTA

La fiche PTA permet de modifier l'ensemble des informations d'une action ou d'un element PTA lorsque l'utilisateur doit faire une modification complete.

Dans le controle PTA, un bouton dedie doit permettre d'acceder directement a cette fiche.

## 11. Module controle et suivi officiel du PTA

### 11.1 Objectif

La page de controle du PTA est une vue de travail rapide permettant de verifier, ajuster et exporter les actions du PTA sans devoir ouvrir chaque fiche individuellement.

### 11.2 Tableau principal

Le tableau affiche les colonnes metier suivantes :

- Action ;
- Sous-action ;
- Indicateur de mesure ;
- RMO ;
- Seuil ;
- Echeance ;
- Observation ;
- commandes ou actions de ligne.

### 11.3 Edition directe dans le tableau

Les champs modifiables doivent pouvoir etre ajustes directement dans la cellule lorsque l'utilisateur dispose du profil autorise.

Champs modifiables sur place :

- action ;
- sous-action ;
- indicateur de mesure ;
- RMO ;
- seuil ;
- echeance ;
- observation.

Le comportement attendu est :

- l'utilisateur clique sur la cellule ;
- le champ devient editable dans le tableau ;
- l'utilisateur modifie la valeur ;
- l'utilisateur enregistre ;
- la ligne est mise a jour sans redirection obligatoire vers une autre page.

### 11.4 Profils autorises a ajuster les donnees

Les boutons et editions directes doivent rester disponibles pour :

- planification ;
- chef planification ;
- SCIQ ;
- suivi global SCIQ ;
- chef d'unite SCIQ ;
- profils assimiles par permissions de configuration.

Ces profils doivent pouvoir corriger ou parametrer les donnees sans blocage inutile lorsque leur mission est l'ajustement ou le controle.

### 11.5 Colonne indicateur de mesure

La colonne indicateur ne doit pas afficher le seuil.

Elle doit afficher :

- le type d'indicateur de mesure ;
- pour un indicateur quantitatif : la quantite a realiser ;
- pour un indicateur non quantitatif : les livrables attendus ;
- pour un indicateur mixte : la quantite a realiser et les livrables attendus.

Ces informations doivent etre modifiables dans la cellule de l'indicateur lorsque l'utilisateur dispose du droit d'edition.

### 11.6 Seuil

Le terme "cible" doit etre remplace par "seuil" dans l'application.

La valeur autrefois appelee cible ne doit plus etre libellee ainsi. Selon le contexte fonctionnel :

- "seuil" designe le niveau attendu ou de controle ;
- "quantite a realiser" designe la valeur attendue pour un indicateur quantitatif.

### 11.7 Colonne commandes

Les boutons qui etaient dans les colonnes Observation ou Action doivent etre regroupes dans une colonne finale dediee.

Cette colonne doit proposer selon les droits :

- enregistrement de modification ;
- ouverture de la fiche PTA ;
- actions de ligne autorisees ;
- acces aux operations de controle.

### 11.8 Filtrage

Les filtres doivent permettre une lecture par objectif operationnel du service consulte dans le tableau.

Filtres attendus :

- exercice ;
- direction ;
- service ;
- objectif operationnel ;
- statut ;
- responsable ;
- recherche texte ;
- indicateur ou type d'indicateur selon implementation.

### 11.9 Exports

Le suivi PTA permet l'export des donnees selon les formats implementes :

- Excel ;
- PDF ;
- tableaux officiels.

### 11.10 Regles d'ergonomie

La page de controle PTA doit favoriser la performance de travail :

- edition en ligne ;
- pas de redirection automatique lors du clic sur une cellule editable ;
- acces direct a la fiche uniquement par bouton dedie ;
- absence de fenetre de previsualisation intempestive dans le tableau interactif ;
- affichage clair des cellules non renseignees.

## 12. Module actions

### 12.1 Objectif

Les actions representent les activites operationnelles a executer dans le PTA.

### 12.2 Donnees principales

- libelle ;
- description ;
- PTA de rattachement ;
- objectif operationnel ;
- service ;
- responsable ;
- statut ;
- progression ;
- date de debut ;
- date d'echeance ;
- indicateurs associes ;
- financement ;
- justificatifs ;
- logs et commentaires.

### 12.3 Fonctions

- creer ou generer une action depuis les modules de planification ;
- modifier une action ;
- consulter une action ;
- suivre son avancement ;
- enregistrer des commentaires ;
- associer des responsables ;
- gerer les sous-actions ;
- rattacher des indicateurs ;
- demander une suppression ;
- demander un report d'echeance ;
- consulter l'historique.

### 12.4 Statuts

Les statuts exacts sont geres par le code et le workflow, notamment :

- brouillon ou preparation ;
- soumis ;
- valide ;
- en cours ;
- termine ;
- rejete ;
- verrouille ou archive selon contexte.

### 12.5 Historique

Les changements importants sont journalises via les logs d'action et le journal d'audit.

## 13. Module sous-actions

### 13.1 Objectif

Les sous-actions permettent de decomposer une action en elements plus precis.

### 13.2 Donnees principales

- libelle ;
- action rattachee ;
- responsable ;
- echeance ;
- indicateurs propres ou rattaches ;
- livrables ;
- statut ;
- avancement.

### 13.3 Fonctions

- ajouter une sous-action ;
- modifier une sous-action ;
- supprimer si autorise ;
- suivre l'avancement ;
- rattacher aux indicateurs du controle PTA.

## 14. Module indicateurs et KPI

### 14.1 Objectif

Les indicateurs mesurent l'execution des plans, actions et sous-actions.

### 14.2 Types d'indicateurs

Le code et les besoins metier distinguent :

- indicateur quantitatif ;
- indicateur non quantitatif ;
- indicateur mixte.

### 14.3 Donnees principales

- type d'indicateur ;
- libelle ;
- unite de mesure ;
- quantite a realiser ;
- livrables attendus ;
- seuil ;
- valeur realisee ;
- periode de mesure ;
- action ou sous-action rattachee.

### 14.4 Fonctions

- creer un indicateur ;
- modifier le type ;
- renseigner les champs attendus selon le type ;
- enregistrer les mesures ;
- calculer l'avancement ;
- alimenter les tableaux de bord ;
- exporter les donnees.

### 14.5 Regles par type

Pour un indicateur quantitatif :

- la quantite a realiser est requise ou attendue ;
- la progression peut etre calculee a partir des valeurs realisees.

Pour un indicateur non quantitatif :

- les livrables attendus doivent etre renseignes ;
- l'evaluation repose sur la realisation ou validation des livrables.

Pour un indicateur mixte :

- la quantite a realiser et les livrables attendus sont tous deux affiches et modifiables ;
- la mesure combine les deux dimensions selon les regles configurees.

## 15. Module mesures KPI

### 15.1 Objectif

Les mesures KPI enregistrent les valeurs periodiques ou ponctuelles des indicateurs.

### 15.2 Fonctions

- ajouter une mesure ;
- modifier une mesure ;
- consulter l'historique des mesures ;
- alimenter les calculs d'avancement ;
- produire les statistiques.

### 15.3 Donnees principales

- KPI rattache ;
- valeur ;
- date ou periode ;
- commentaire ;
- justificatif eventuel ;
- auteur de saisie.

## 16. Module justificatifs

### 16.1 Objectif

Les justificatifs documentent l'execution d'une action, d'une sous-action ou d'une mesure.

### 16.2 Fonctions

- televerser un fichier ;
- rattacher le fichier a une action ou mesure ;
- consulter les fichiers autorises ;
- supprimer si autorise ;
- verifier la presence de pieces justificatives.

### 16.3 Regles

- Les droits de consultation dependent du perimetre et du role.
- Les pieces peuvent etre utilisees lors des validations ou controles.

## 17. Module demandes de suppression

### 17.1 Objectif

Permettre la suppression controlee d'une action ou d'un element sensible.

### 17.2 Workflow

1. Un utilisateur autorise soumet une demande de suppression.
2. La demande est enregistree avec motif.
3. Les profils de validation sont notifies.
4. La demande est approuvee ou rejetee.
5. L'action resultante est journalisee.

### 17.3 Regles

- Une suppression directe n'est pas toujours autorisee.
- La demande conserve une trace d'audit.

## 18. Module demandes de report d'echeance

### 18.1 Objectif

Permettre de demander une modification d'echeance sans contourner le workflow de controle.

### 18.2 Workflow

1. L'utilisateur cree une demande de report pour une action ou une sous-action.
2. Il indique la nouvelle date souhaitee, le motif, la justification detaillee et joint obligatoirement une piece justificative.
3. Le chef de service rend un avis.
4. Un controleur SCIQ ou Planification rend un avis.
5. Le DG ou le Chef Planification rend la decision finale.
6. Meme apres approbation finale, la date reste inchangee tant qu'un controleur ne l'a pas appliquee.
7. Seuls les controleurs peuvent appliquer la date approuvee.
8. En cas de demande de complement, seul le demandeur peut completer le meme dossier avec une nouvelle piece. Le dossier revient a l'etape qui a demande le complement sans perdre les avis anterieurs.
9. Chaque revision, decision et application est notifiee et journalisee. Toutes les pieces justificatives successives restent telechargeables par les utilisateurs autorises.
10. Les formulaires, API et imports Excel ou IA ne peuvent pas modifier les dates d'une action existante en dehors de ce circuit.
11. Une file centralisee "Reports echeance" est accessible a tous les profils. La vue "A traiter" affiche uniquement les dossiers correspondant au role et a l'etape courante ; la vue "Mes demandes" restitue l'historique du demandeur.
12. Chaque dossier dispose d'une fiche dediee affichant la progression du circuit, les dates, les pieces successives et les decisions. La zone de traitement ne montre que la commande autorisee pour le profil connecte.
13. La navigation affiche un compteur des reports reellement assignes au profil. La file est pre-filtree par role, perimetre de service et delegation avant l'application des politiques d'autorisation.

## 19. Module financement

### 19.1 Objectif

Suivre les informations financieres associees aux actions.

### 19.2 Donnees principales

- budget previsionnel ;
- montant engage ;
- montant realise ou liquide ;
- source de financement ;
- statut de validation ;
- rattachement a l'action.

### 19.3 Fonctions

- renseigner les informations de financement ;
- soumettre a validation ;
- valider ou rejeter selon role ;
- consolider les montants ;
- alimenter les rapports.

## 20. Module alertes et notifications

### 20.1 Objectif

Alerter les utilisateurs sur les evenements importants.

### 20.2 Types d'alertes

- actions en retard ;
- echeances proches ;
- demandes a valider ;
- imports termines ou en erreur ;
- anomalies de donnees ;
- notifications systeme ;
- rapports disponibles.

### 20.3 Fonctions

- afficher les alertes ;
- marquer comme lue ;
- envoyer des notifications ;
- filtrer les alertes ;
- alimenter les tableaux de bord.

## 21. Module reporting et exports

### 21.1 Objectif

Produire des documents de pilotage, de controle et de presentation.

### 21.2 Formats

Les dependances et routes montrent la prise en charge de :

- PDF ;
- Excel ;
- Word ;
- exports officiels de tableaux.

### 21.3 Fonctions

- generer un rapport ;
- exporter une liste ;
- exporter un tableau de bord ;
- produire un rapport de suivi PTA ;
- telecharger les fichiers ;
- utiliser des modeles d'export parametrables.

### 21.4 Rapports identifies

- rapport de synthese ;
- rapports PTA ;
- rapports d'actions ;
- reporting KPI ;
- rapports IA ;
- exports officiels de controle.

## 22. Module imports Excel

### 22.1 Objectif

Importer des donnees de planification ou de suivi depuis des fichiers Excel.

### 22.2 Fonctions

- televerser un fichier ;
- previsualiser les donnees ;
- mapper les colonnes ;
- valider les lignes ;
- enregistrer les donnees ;
- consulter les erreurs ;
- tracer l'import.

### 22.3 Regles

- Les imports doivent controler les doublons et valeurs manquantes.
- Les erreurs doivent etre restituees a l'utilisateur.
- Les imports sensibles doivent etre auditables.

## 23. Module imports assistes par IA

### 23.1 Objectif

Faciliter l'import de donnees PTA a partir de documents ou fichiers en utilisant une assistance IA.

### 23.2 Fonctions

- importer un document ;
- analyser automatiquement le contenu ;
- extraire des lignes proposees ;
- auditer le resultat ;
- permettre la validation humaine ;
- enregistrer les lignes validees ;
- conserver l'historique de traitement.

### 23.3 Regles

- Les resultats IA doivent rester soumis a validation humaine.
- Les lots, lignes et audits d'import sont traces.
- Les erreurs ou incertitudes doivent pouvoir etre consultees.

## 24. Module rapports IA

### 24.1 Objectif

Generer des rapports ou analyses a partir des donnees de pilotage.

### 24.2 Fonctions

- lancer une generation ;
- consulter les rapports generes ;
- exporter ou telecharger ;
- stocker les resultats ;
- collecter des retours utilisateurs ;
- alimenter la base de connaissance IA.

### 24.3 Regles

- Le rapport IA est une aide a l'analyse.
- Les donnees utilisees doivent respecter les droits d'acces.
- Les resultats doivent rester controlables par l'utilisateur.

## 25. Module gouvernance et audit

### 25.1 Objectif

Assurer la tracabilite, la qualite et la gouvernance des operations.

### 25.2 Fonctions

- journal d'audit ;
- consultation des evenements ;
- suivi des modifications sensibles ;
- historique des actions ;
- delegations ;
- retention ou archivage de donnees ;
- documentation API ;
- diagnostics de plateforme.

### 25.3 Donnees journalisees

- utilisateur ;
- action realisee ;
- entite concernee ;
- ancienne valeur et nouvelle valeur selon contexte ;
- date et heure ;
- adresse ou contexte technique si disponible.

## 26. Module super-administration

### 26.1 Objectif

Centraliser le parametrage global de l'application.

### 26.2 Fonctions principales

- tableau de bord administrateur ;
- gestion des utilisateurs ;
- gestion des roles et permissions ;
- gestion des modules ;
- parametrage de la plateforme ;
- parametrage des workflows ;
- gestion des exercices ;
- referentiels ;
- templates d'export ;
- notifications ;
- apparence ;
- politiques d'action ;
- snapshots de configuration ;
- simulation de droits ou d'impact ;
- diagnostic et maintenance.

### 26.3 Regles

- Les actions d'administration sont reservees aux profils autorises.
- Les modifications critiques doivent etre tracees.
- Les changements de parametrage peuvent impacter les workflows.

## 27. API REST

### 27.1 Objectif

Fournir un acces programme aux principales ressources de pilotage.

### 27.2 Authentification API

L'API expose des routes d'authentification :

- login ;
- utilisateur courant ;
- logout.

Les routes protegees utilisent l'authentification Sanctum.

### 27.3 Ressources exposees

Les ressources API identifiees comprennent :

- referentiels ;
- PAS ;
- axes PAS ;
- objectifs PAS ;
- PAO ;
- PTA ;
- KPI ;
- mesures KPI ;
- actions ;
- commentaires et logs d'action ;
- audit ;
- reporting ;
- alertes.

### 27.4 Regles

- L'API est versionnee sous un prefixe v1.
- Les reponses doivent respecter le perimetre de l'utilisateur connecte.
- Les operations sensibles restent soumises aux permissions.

## 28. Workflows principaux

### 28.1 Cycle PAS / PAO / PTA

1. Parametrage des referentiels et exercices.
2. Creation du PAS.
3. Definition des axes et objectifs strategiques.
4. Creation du PAO rattache.
5. Definition des objectifs operationnels.
6. Creation du PTA.
7. Creation des actions et sous-actions.
8. Parametrage des indicateurs.
9. Validation ou verrouillage selon workflow.
10. Execution et suivi.
11. Reporting et audit.

### 28.2 Cycle d'execution d'une action

1. L'action est creee ou importee.
2. Les responsables et echeances sont definis.
3. Les indicateurs sont rattaches.
4. L'utilisateur renseigne l'avancement.
5. Les justificatifs sont ajoutes.
6. Les KPI sont mesures.
7. Les alertes sont declenchees si necessaire.
8. L'action est cloturee ou revisee.

### 28.3 Controle PTA

1. Le controleur ouvre la page de suivi PTA.
2. Il filtre par exercice, service ou objectif operationnel.
3. Il identifie les cellules incompletes ou a corriger.
4. Il clique dans la cellule a corriger.
5. Il modifie la valeur en ligne.
6. Il enregistre.
7. Il exporte le tableau ou ouvre la fiche PTA si une correction complete est necessaire.

### 28.4 Import Excel

1. L'utilisateur televerse le fichier.
2. L'application lit les donnees.
3. L'utilisateur verifie ou mappe les colonnes.
4. Les erreurs sont affichees.
5. Les lignes valides sont importees.
6. Un journal d'import est conserve.

### 28.5 Import IA

1. L'utilisateur televerse un document.
2. L'IA extrait les donnees probables.
3. L'utilisateur verifie les propositions.
4. Il corrige ou rejette les lignes.
5. Les lignes validees sont enregistrees.
6. L'audit d'import est conserve.

### 28.6 Demande de suppression

1. L'utilisateur initie une demande.
2. Il indique un motif.
3. La demande est soumise.
4. Le validateur examine.
5. Il accepte ou refuse.
6. La decision est notifiee et journalisee.

### 28.7 Demande de report d'echeance

1. L'utilisateur demande une nouvelle echeance.
2. Il precise le motif.
3. Le validateur statue.
4. La date est mise a jour si la demande est acceptee.
5. L'historique est conserve.

## 29. Regles de gestion transverses

### 29.1 Perimetre d'acces

Les utilisateurs ne doivent acceder qu'aux donnees autorisees par :

- role ;
- permission ;
- direction ;
- service ;
- unite ;
- delegation ;
- statut de l'objet.

### 29.2 Tracabilite

Les operations sensibles doivent etre tracees.

Exemples :

- creation ;
- modification ;
- suppression ;
- validation ;
- rejet ;
- import ;
- export sensible ;
- modification de droits ;
- changement de workflow.

### 29.3 Validation humaine

Les fonctions d'import ou de generation assistees par IA ne remplacent pas la validation humaine.

### 29.4 Cohesion des donnees

Les relations PAS, PAO, PTA, actions, sous-actions et KPI doivent rester coherentes.

### 29.5 Libelles metier

Le mot "cible" ne doit plus etre utilise pour les champs metier de controle PTA. Les libelles attendus sont :

- seuil ;
- quantite a realiser ;
- livrables attendus.

## 30. Donnees principales du systeme

Les entites majeures identifiees sont :

- utilisateur ;
- direction ;
- service ;
- unite DG ;
- exercice ;
- PAS ;
- axe PAS ;
- objectif PAS ;
- PAO ;
- axe PAO ;
- objectif PAO ;
- PTA ;
- action ;
- sous-action ;
- responsable d'action ;
- KPI ;
- mesure KPI ;
- justificatif ;
- demande de suppression ;
- demande de report ;
- notification ;
- alerte lue ;
- journal d'audit ;
- delegation ;
- archive ;
- import planning ;
- demande de deverrouillage planning ;
- lot d'import IA ;
- ligne d'import IA ;
- audit d'import IA ;
- rapport IA ;
- connaissance IA ;
- retour IA ;
- template d'export ;
- parametre de plateforme ;
- snapshot de plateforme.

## 31. Etats, statuts et controles

Les objets metier peuvent passer par plusieurs etats selon leur nature :

- brouillon ;
- soumis ;
- valide ;
- rejete ;
- en cours ;
- termine ;
- verrouille ;
- archive ;
- en attente de validation ;
- en erreur ;
- importe ;
- ignore.

Les statuts doivent etre affiches clairement et ne doivent pas permettre des actions incompatibles avec le workflow.

## 32. Exigences d'ergonomie

L'application doit favoriser :

- navigation rapide entre modules ;
- tableaux lisibles ;
- filtres par exercice, direction, service et objectif ;
- edition directe lorsque cela accelere le controle ;
- boutons d'action clairement regroupes ;
- messages d'erreur comprehensibles ;
- confirmation pour les operations sensibles ;
- exports accessibles depuis les vues metier.

## 33. Exigences de restitution

Les donnees doivent pouvoir etre restituees sous forme :

- de tableaux ;
- de fiches ;
- de tableaux de bord ;
- de graphiques selon les vues existantes ;
- de rapports PDF ;
- d'exports Excel ;
- de documents Word ;
- de rapports IA ;
- de journaux d'audit.

## 34. Limites fonctionnelles identifiees

Le code ne positionne pas l'application comme :

- un logiciel comptable complet ;
- un outil de paie ;
- un ERP global ;
- une GED complete ;
- un systeme decisionnel externe independant ;
- un moteur IA autonome sans validation humaine.

Ces usages peuvent etre connectes ou etendus, mais ne constituent pas le coeur fonctionnel observe.

## 35. Criteres de recette fonctionnelle

### 35.1 Authentification

- Un utilisateur autorise peut se connecter.
- Un utilisateur non autorise ne peut pas acceder aux routes protegees.
- La deconnexion invalide la session.

### 35.2 PAS / PAO / PTA

- Les plans peuvent etre crees, consultes, modifies et listes selon permissions.
- Les rattachements entre PAS, PAO et PTA sont conserves.
- Les statuts limitent les actions non autorisees.

### 35.3 Controle PTA

- Les profils planification et SCIQ peuvent editer les cellules autorisees.
- Le clic sur une cellule editable ne redirige pas vers une autre page.
- La colonne indicateur affiche le type d'indicateur et ses champs metier.
- Les boutons sont regroupes dans la colonne finale.
- Le bouton de fiche PTA ouvre la page de modification complete.
- Les filtres permettent de travailler par objectif operationnel du service.

### 35.4 Indicateurs

- Un indicateur quantitatif affiche la quantite a realiser.
- Un indicateur non quantitatif affiche les livrables attendus.
- Un indicateur mixte affiche les deux.
- Le seuil est gere dans sa colonne dediee.

### 35.5 Reporting

- Les exports PDF et Excel generent des fichiers exploitables.
- Les rapports respectent le perimetre de l'utilisateur.

### 35.6 Imports

- Les fichiers valides sont importes.
- Les erreurs sont affichees.
- Les imports sont traces.

### 35.7 Gouvernance

- Les operations sensibles apparaissent dans le journal d'audit.
- Les droits et roles sont appliques.

## 36. Annexes techniques consultees

Elements du code utilises pour etablir ce document :

- routes web ;
- routes API ;
- modeles Eloquent ;
- migrations ;
- form requests ;
- services applicatifs ;
- vues Blade ;
- tests Feature ;
- documentation existante dans le dossier docs ;
- README et guide projet.

## 37. Complement d'integration des documents Word CSP/DS

Deux documents Word ont ete compares avec le present cahier :

- `C:\Users\chris\OK\CSF_ePilotage_ANBG.docx` ;
- `C:\Users\chris\OK\CDC_ePilotage_ANBG.docx`.

Ces documents ne remplacent pas les specifications issues du code. Ils apportent un complement fonctionnel important sur la refonte des tableaux de bord, la navigation par profil, les alertes et la simplification de la saisie d'avancement.

### 37.1 Decision d'integration

Les exigences des documents Word doivent etre integrees comme une evolution des ecrans existants.

Il ne faut pas supprimer :

- le design existant de l'application ;
- la page de suivi officiel du PTA ;
- les vues de synthese ;
- les graphiques ;
- les vues detaillees ;
- les exports deja disponibles ;
- les parcours de consultation existants.

L'implementation attendue consiste a enrichir et reorganiser les tableaux de bord, pas a remplacer brutalement les modules deja presents.

### 37.2 Adaptation technique au code actuel

Les documents Word mentionnent Laravel 12, PHP 8.2, PostgreSQL et Filament v3. Le code local analyse correspond a une application Laravel 13, PHP 8.4, Blade/Tailwind, Sanctum et services metier Laravel.

La specification fonctionnelle retient donc :

- les besoins metier des documents Word ;
- l'architecture technique reelle du code local ;
- les composants existants de l'application ;
- les vues Blade et composants de tableau deja presents ;
- les tests et services existants comme reference d'implementation.

Les mentions de composants Filament dans les Word doivent etre traduites en composants equivalents de l'application actuelle : cartes de statistiques, onglets, tableaux, badges, vues detaillees, graphiques et formulaires Blade/Tailwind.

### 37.3 Adaptation du vocabulaire metier

Les documents Word emploient encore le mot "cible". Dans l'application, ce mot ne doit plus etre affiche comme libelle de champ metier.

Regle d'adaptation :

- pour les indicateurs de controle PTA, utiliser "seuil" ;
- pour les indicateurs quantitatifs, utiliser "quantite a realiser" ;
- pour les indicateurs non quantitatifs, utiliser "livrables attendus" ;
- pour les indicateurs mixtes, afficher "quantite a realiser" et "livrables attendus" ;
- pour la vision strategique 2026-2028, utiliser "niveau attendu", "objectif 2028" ou "trajectoire attendue" selon le contexte.

## 38. Architecture de navigation des tableaux de bord

### 38.1 Principe general

Les tableaux de bord doivent suivre une navigation progressive sur trois niveaux :

- PAS : vision strategique et trajectoire pluriannuelle ;
- PAO : axes, objectifs operationnels et contribution des directions ;
- PTA : execution annuelle, activites, budget, alertes et rapports.

Cette navigation doit respecter le principe de drill-down progressif :

- un clic sur une carte PAS ouvre le detail PAS ou le niveau PAO associe ;
- un clic sur un axe PAO ouvre les objectifs et activites rattachees ;
- un clic sur un indicateur PTA ouvre le detail PTA, les alertes, l'execution par direction ou la conformite des rapports ;
- aucun clic ne doit imposer un saut brutal sans contexte entre deux niveaux eloignes.

### 38.2 Fil d'Ariane

Les vues detaillees doivent afficher le chemin de navigation :

- PAS ;
- PAO ;
- PTA ;
- direction ;
- service ;
- action ou activite.

L'utilisateur doit pouvoir revenir a un niveau superieur sans perdre son contexte.

### 38.3 Densite progressive

Chaque tableau de bord d'accueil doit afficher en priorite les indicateurs essentiels.

Regles :

- 4 a 6 cartes maximum doivent etre visibles au premier niveau d'accueil ;
- les indicateurs supplementaires doivent etre places dans des onglets, sections repliables, graphiques secondaires ou vues detaillees ;
- les profils de supervision peuvent acceder au detail, mais ne doivent pas etre confrontes a une surcharge d'indicateurs des l'ouverture ;
- les tableaux complets restent disponibles dans les vues detaillees ou les pages de suivi.

### 38.4 Conservation des vues existantes

Les tableaux de bord refondus ne doivent pas supprimer les vues actuelles.

Les ecrans suivants doivent rester accessibles :

- page de suivi PTA ;
- synthese globale ;
- graphiques ;
- vues detaillees d'action ;
- fiches PTA ;
- vues d'export ;
- tableaux de controle ;
- vues d'administration.

La refonte doit ameliorer l'organisation, les filtres, les cartes et les raccourcis, tout en conservant les parcours metier utiles.

## 39. Moteur d'alerte transverse

### 39.1 Objectif

Le moteur d'alerte sert a signaler rapidement les situations necessitant une action.

Il s'applique aux indicateurs porteurs de seuils, retards, ecarts, donnees manquantes ou anomalies de fiabilite.

### 39.2 Niveaux d'alerte

Les niveaux fonctionnels attendus sont :

- critique : action immediate requise ;
- vigilance : situation a surveiller ou a corriger ;
- conforme : situation normale ;
- information : statut neutre ou informatif.

### 39.3 Restitution visuelle

Chaque alerte doit etre affichee avec :

- une couleur ;
- un libelle ;
- une icone ou marqueur visuel lorsque disponible ;
- une description courte ;
- un lien vers le detail concerne.

La couleur ne doit jamais etre le seul moyen de comprehension. Cette regle est importante pour l'accessibilite.

### 39.4 Couleurs recommandees

Les couleurs recommandees par les documents Word sont :

- critique : rouge `#C0392B` ;
- vigilance : orange `#E08E0B` ;
- conforme : vert `#2E7D32` ;
- information ou neutre : gris-bleu `#1F3864`.

Ces couleurs doivent etre adaptees si le design system existant possede deja des tokens plus coherents, a condition de conserver le sens fonctionnel.

### 39.5 Parametrage

Les seuils d'alerte doivent etre parametrables sans modification de code lorsque le modele de donnees le permet.

Parametres attendus :

- indicateur concerne ;
- seuil de vigilance ;
- seuil critique ;
- sens de comparaison ;
- periode d'application ;
- profil ou perimetre concerne ;
- statut actif ou inactif.

### 39.6 Historique

L'application doit conserver l'historique des changements de niveau d'alerte lorsque cette information est utile au suivi.

Donnees attendues :

- indicateur ;
- ancien niveau ;
- nouveau niveau ;
- date de changement ;
- source du changement ;
- utilisateur ou processus a l'origine.

## 40. Specifications par profil de tableau de bord

### 40.1 Direction generale

Le tableau de bord Direction generale doit offrir une vision consolidee de l'Agence.

Niveau PAS :

- vision strategique 2026-2028 ;
- indicateurs de trajectoire strategique ;
- niveau attendu a l'horizon 2028 ;
- ecart de trajectoire ;
- barre de progression ou graphique equivalent.

Niveau PAO :

- progression par axe strategique ;
- nombre d'objectifs rattaches ;
- statut global des objectifs ;
- acces au detail des objectifs de l'axe.

Niveau PTA :

- taux d'execution global du PTA ;
- activites realisees ;
- activites en retard, dont retards critiques ;
- execution budgetaire ;
- rapports remis dans les delais.

Le niveau PTA doit conserver des onglets ou vues d'approfondissement :

- alertes PTA ;
- execution par direction ;
- conformite des rapports.

### 40.2 Directeur

Le tableau de bord Directeur doit etre restreint a sa direction.

Indicateurs attendus :

- taux d'execution de la direction ;
- activites realisees ;
- activites en retard ;
- budget execute ;
- rapports remis par les services ;
- contribution de la direction aux axes PAS ;
- performance comparee des services rattaches ;
- alertes critiques ou en vigilance.

Un clic sur un service doit ouvrir le detail du service sans retirer la vue de synthese de la direction.

### 40.3 Chef de service

Le tableau de bord Chef de service doit piloter le service et les agents rattaches.

Indicateurs attendus :

- taux d'execution du service ;
- activites realisees ;
- activites en retard ;
- rapports produits ;
- taux de realisation des activites PTA du service ;
- delai de transmission des rapports ;
- reunions de suivi organisees ;
- performance individuelle des agents.

Les alertes affichees doivent prioriser les situations critiques et de vigilance.

### 40.4 Agent

Le tableau de bord Agent doit rester simple et operationnel.

Il doit afficher :

- activites assignees ;
- filtre PTA complet ;
- filtre mois en cours ;
- filtre trimestre ;
- filtre semestre ;
- taux de realisation individuel ;
- activites en retard ;
- activites en cours ou dans les delais.

La saisie courante d'avancement doit etre minimale :

- pourcentage d'avancement ;
- date de mise a jour ;
- commentaire court.

Les informations complementaires, justificatifs ou details methodologiques doivent rester accessibles, mais ne doivent pas alourdir la saisie courante.

### 40.5 Chef de service planification

Le tableau de bord Planification doit fournir une vue transverse.

Fonctions attendues :

- taux d'execution PTA toutes directions ;
- progression des axes PAS ;
- comparaison entre directions ;
- comparaison entre services ;
- detection des activites PTA non rattachees a un objectif PAO ;
- detection des objectifs PAO sans activite PTA associee ;
- suivi du processus de reporting ;
- calendrier des prochaines echeances ;
- statut de preparation du prochain PTA.

Ces fonctions completent la page de controle PTA et ne doivent pas la remplacer.

### 40.6 Gestionnaire suivi-evaluation, SCIQ et suivi global

Le tableau de bord Suivi-evaluation / SCIQ doit se concentrer sur la qualite et la fiabilite de la donnee.

Indicateurs attendus :

- activites sans mise a jour depuis 30 jours ;
- indicateurs sans source de collecte renseignee ;
- ecarts entre donnees declarees et donnees verifiees ;
- indicateurs sans seuil ou niveau attendu defini ;
- missions de controle interne planifiees et realisees ;
- recommandations emises et soldees ;
- activites declarees a 100% sans justificatif ;
- modifications posterieures suspectes.

Chaque alerte de fiabilite doit donner acces au detail de l'activite ou de la modification concernee.

## 41. Workflow detaille de report d'echeance

Le workflow existant de report d'echeance doit etre complete par les regles issues des documents Word.

Etapes attendues :

1. L'agent ou l'utilisateur autorise demande un report depuis l'action ou la sous-action concernee.
2. Il saisit une nouvelle date souhaitee, un motif, une justification detaillee et une piece justificative obligatoire.
3. Une seule demande active doit exister par element concerne.
4. Le chef de service rend un avis favorable, defavorable ou demande un complement.
5. Apres avis favorable du chef, un controleur SCIQ ou Planification rend son avis.
6. Apres avis favorable du controleur, le DG ou le Chef Planification approuve, rejette ou demande un complement.
7. L'approbation finale ne modifie aucune date.
8. Seul un controleur peut appliquer la date approuvee apres la decision finale.
9. En cas de complement, seul le demandeur peut reviser le dossier et joindre une nouvelle piece. Le dossier revient a l'etape ayant demande le complement.
10. Les avis deja acquis aux etapes anterieures restent traces et le nombre de revisions est conserve.
11. Les tableaux de bord des profils concernes et les notifications sont mis a jour a chaque transition.
12. Le motif, les pieces successives telechargeables, les avis, la decision finale et l'application restent dans l'historique d'audit.
13. Les modifications directes de date sont interdites depuis le tableau, les fiches, l'API et les imports Excel ou IA.
14. Le nombre de reports successifs devient un signal de risque methodologique.
15. Une boite de traitement centralisee route automatiquement le dossier vers le Chef de service, les controleurs, le DG ou Chef Planification, puis le controleur charge d'appliquer la date.
16. Le bouton "Traiter" ouvre une fiche de report autonome ; une decision prise depuis cette fiche y reste apres enregistrement, tandis que le parcours depuis la fiche action conserve son retour initial.
17. La file est paginee, recherchable et triee de facon stable du dossier le plus recent au plus ancien.

## 42. Matrice fonctionnelle de permissions issue des documents Word

La matrice ci-dessous complete les roles existants du code. Elle ne remplace pas les permissions detaillees de l'application.

| Profil | Lecture | Ecriture avancement | Validation report | Parametrage alertes |
| --- | --- | --- | --- | --- |
| Direction generale | Toute l'Agence | Non | Non | Non, sauf delegation |
| Directeur | Direction et services rattaches | Non | Selon delegation | Non |
| Chef de service | Service et agents rattaches | Validation ou suivi selon droits | Oui pour ses agents | Non |
| Agent | Activites propres | Oui sur ses activites | Non | Non |
| Planification | Vue transverse consolidee | Ajustements autorises selon mission | Non ou selon delegation | Non |
| Suivi-evaluation / SCIQ | Vue transverse qualite donnee | Ajustements de controle selon mission | Non ou selon delegation | Non |
| Administrateur fonctionnel | Toute l'Agence | Selon parametrage | Selon parametrage | Oui |

Les profils planification, SCIQ et responsables habilites doivent conserver les boutons et editions directes necessaires a leur mission de controle.

## 43. Complements au modele de donnees

Les documents Word identifient des donnees complementaires utiles. Certaines existent deja dans le code, d'autres peuvent etre a completer selon l'etat reel du schema.

Complements a verifier ou implementer :

- seuils d'alerte parametrables par indicateur ;
- historique des statuts d'alerte ;
- source de collecte d'un indicateur ;
- justificatif optionnel rattache a une activite ou mesure ;
- journal des modifications posterieures ;
- demande de report d'echeance avec ancienne date, nouvelle date, motif, statut et validateur ;
- rattachement obligatoire d'une activite PTA a un objectif PAO lorsque la regle metier l'exige ;
- niveau attendu 2028 pour les indicateurs de vision strategique, sans utiliser le libelle applicatif "cible".

Ces complements doivent rester additifs lorsque c'est possible afin de ne pas casser les donnees historiques.

## 44. Composants d'interface attendus

Les composants fonctionnels attendus sont :

- carte de statistique avec valeur, niveau attendu ou seuil, progression et alerte ;
- onglets d'approfondissement ;
- tableau filtrable ;
- badge de statut ;
- graphique de progression ;
- vue detaillee ;
- fil d'Ariane ;
- formulaire court de mise a jour ;
- curseur ou champ numerique d'avancement ;
- bouton d'export ;
- bouton d'acces a la fiche PTA ;
- bouton d'acces au detail de l'action ;
- panneau d'alertes.

Les composants doivent respecter le design existant, les conventions Blade/Tailwind de l'application et les pages deja en production.

## 45. Criteres de recette complementaires

Les criteres suivants completent la section 35.

- Chaque profil dispose d'un tableau de bord d'accueil limite a 4 a 6 indicateurs cles.
- Les indicateurs supplementaires sont accessibles par onglets, sections ou vues detaillees.
- La navigation PAS, PAO, PTA fonctionne par exploration progressive.
- Le fil d'Ariane permet de revenir aux niveaux superieurs.
- Les alertes critiques et de vigilance sont visibles sans lecture exhaustive du tableau.
- Les alertes sont accompagnees d'un libelle ou d'une icone, pas seulement d'une couleur.
- La saisie d'avancement d'un agent reste limitee aux champs essentiels.
- Le workflow de report fonctionne de bout en bout.
- Les vues existantes de suivi PTA, synthese, graphiques et detail restent accessibles.
- Les profils planification et SCIQ conservent leurs fonctions de controle et d'ajustement.

## 46. Tableau de bord riche par profil

La page d'accueil ne doit pas se limiter a une vue essentielle composee de quelques cartes. Elle doit afficher un centre de pilotage riche, sans supprimer les vues analytiques existantes.

- Le DG et le cabinet disposent d'un libelle de pilotage executif.
- La planification, le SCIQ et les profils de controle disposent d'un libelle de pilotage administratif.
- Les directions, services et agents disposent d'une lecture adaptee a leur perimetre.
- La premiere zone affiche au maximum six indicateurs de synthese avec valeur, contexte et acces au detail.
- La zone Flux a traiter affiche les validations, les reports d'echeance actionnables et les alertes critiques.
- Le compteur de reports d'echeance est calcule selon l'etape du workflow, les delegations et les autorisations de l'utilisateur.
- Les onglets Synthese, Graphiques et Vue detaillee restent disponibles.
- Le tableau de bord conserve les filtres par exercice, periode, direction, service, statut de suivi, statut de delai et alerte d'echeance.

## 47. PAO annuel multi-axes

### 47.1 Structure

- Une direction dispose au maximum d'un PAO pour une annee donnee.
- Un index unique protege cette regle contre les soumissions concurrentes, sans bloquer la recreation apres suppression logique.
- Un PAO contient un ou plusieurs objectifs operationnels.
- Chaque objectif operationnel reference son objectif strategique, son axe par relation, son service destinataire et son echeance.
- Tous les objectifs strategiques selectionnes dans un PAO appartiennent au meme PAS.

### 47.2 Controles

- La direction d'un service destinataire doit correspondre a la direction du PAO.
- L'annee du PAO doit appartenir a la periode du PAS.
- L'echeance operationnelle doit appartenir a l'annee du PAO.
- L'echeance operationnelle ne peut pas depasser celle de l'objectif strategique rattache.
- Deux objectifs operationnels identiques ne peuvent pas etre ajoutes pour le meme objectif strategique et le meme service.
- Une modification ne peut pas reutiliser l'identifiant d'un objectif operationnel appartenant a un autre PAO.
- Un objectif operationnel lie a un PTA ou une action ne peut pas etre retire du PAO.

### 47.3 Interfaces et compatibilite

- Le formulaire Web permet de selectionner un objectif strategique distinct sur chaque ligne operationnelle.
- Les options proposees sont limitees au PAS principal selectionne.
- La liste PAO affiche les objectifs strategiques et les services effectivement couverts.
- Les filtres Web et API prennent en compte les rattachements portes par les objectifs operationnels.
- Les anciens payloads API sans rattachement detaille restent acceptes : l'objectif strategique principal est applique par defaut.

## 48. Exploration progressive du PAS

### 48.1 Parcours de lecture

- Chaque PAS propose un acces permanent `Explorer` depuis la liste, y compris pour les profils autorises en lecture seule.
- La page restitue la chaine PAS, axes, objectifs strategiques, PAO, objectifs operationnels, PTA et actions.
- Les axes et objectifs sont ouvrables progressivement afin de conserver une lecture executive en premier niveau et un detail administratif a la demande.
- Un PAO couvrant plusieurs objectifs strategiques apparait sous chacun des objectifs effectivement portes par ses objectifs operationnels.
- Les liens de chaque niveau ouvrent les listes PAO, PTA ou Actions avec le filtre correspondant.

### 48.2 Indicateurs et ruptures

- La synthese affiche le nombre d'axes, d'objectifs strategiques, de PAO, d'objectifs operationnels, de PTA et d'actions visibles.
- Le taux de couverture strategique correspond a la part des objectifs strategiques possedant au moins un objectif operationnel visible.
- Un objectif strategique sans objectif operationnel est signale comme non decline.
- Un objectif operationnel sans PTA est signale par `PTA manquant`.
- Un PTA sans action est signale par `Aucune action`.
- Les axes et objectifs vides restent affiches afin que l'absence de declinaison ne soit pas masquee.

### 48.3 Autorisation et performance

- Les profils globaux autorises voient l'ensemble de la declinaison.
- Un directeur ne voit que les PAO et donnees operationnelles de sa direction.
- Un chef de service ou d'unite ne voit que les objectifs operationnels et PTA de son service, y compris lorsqu'un PAO couvre plusieurs services.
- Une URL directe vers un PAS hors perimetre est refusee par la politique serveur.
- Les agents restent exclus de l'explorateur strategique et conservent leurs vues d'execution dediees.
- La hierarchie est chargee par requetes contraintes avant le rendu ; la vue Blade n'execute aucune requete metier.

## 49. Fiche operationnelle du PAO

### 49.1 Synthese et navigation

- Chaque ligne PAO propose un acces `Explorer` a tous les profils autorises en lecture.
- La fiche affiche la direction, l'exercice, l'echeance, le statut et l'avancement moyen des actions visibles.
- Six indicateurs resument les objectifs strategiques couverts, les services, les objectifs operationnels, les PTA, les actions en retard et les reports actifs.
- La fiche permet de revenir a la liste PAO, au PAS parent et au formulaire PAO lorsque l'utilisateur possede le droit de modification.

### 49.2 Hierarchie operationnelle

- Les objectifs operationnels sont regroupes sous leur objectif strategique et leur axe reels.
- Chaque objectif operationnel affiche son service, son echeance, son statut, son avancement et ses PTA.
- Chaque PTA affiche ses actions, leur responsable, leur echeance, leur avancement, leur statut et le report actif eventuel.
- Une action expose uniquement les commandes `Faire le suivi` et `Report d'echeance` ; ces commandes ouvrent la page de suivi existante et ses controles serveur.
- Un objectif operationnel sans PTA et un PTA sans action possedent des etats vides explicites.

### 49.3 Controle et perimetre

- Les profils globaux voient toute la fiche PAO.
- Une direction ne peut ouvrir que les PAO de son perimetre.
- Un service ne voit dans un PAO multi-services que ses propres objectifs operationnels, PTA et actions.
- Une URL directe hors perimetre retourne une interdiction serveur.
- Les agents restent orientes vers leurs pages d'execution et ne peuvent pas ouvrir l'explorateur PAO.
- Les calculs d'avancement utilisent la source de progression metier existante et les relations sont chargees avant le rendu pour eviter les requetes N+1.

## 50. Fiche administrative du PTA

### 50.1 Synthese et rattachement

- Chaque ligne PTA propose un acces permanent `Explorer`, y compris pour les profils autorises en lecture seule.
- La fiche affiche le service, la direction, l'exercice, l'echeance de l'objectif operationnel, le statut, le verrouillage et l'avancement moyen.
- Six indicateurs resument les actions, les sous-actions, les actions a parametrer, les retards, les reports actifs et les validations en attente.
- Le rattachement PAS, axe strategique, objectif strategique, PAO et objectif operationnel est restitue dans une chaine administrative unique.
- Les liens du PAS et du PAO ouvrent les explorateurs existants sans creer de navigation parallele.

### 50.2 Tableau des actions

- Le tableau conserve une ligne par action et affiche le type, l'indicateur, la cible, le resultat attendu, le RMO, la periode, l'avancement, les sous-actions, les preuves, la validation et le report actif.
- Les sous-actions sont resumees par le nombre planifie, le nombre existant et le nombre termine.
- Une action expose uniquement les commandes `Faire le suivi` et `Report d'echeance` dans sa colonne d'actions.
- Un report actif ouvre sa fiche de gouvernance et affiche la nouvelle date demandee ou approuvee sans modifier directement la date de l'action.
- Un PTA sans action possede un etat vide explicite.

### 50.3 Qualite, securite et performance

- Les actions sans parametrage, responsable, echeance ou cible sont signalees avec les retards et reports actifs.
- Les profils globaux autorises voient toute la fiche ; les directions et services restent limites a leur perimetre organisationnel.
- Une URL directe vers un PTA hors perimetre retourne une interdiction serveur.
- Les agents conservent leurs vues d'execution dediees et ne peuvent pas ouvrir cette fiche administrative.
- La fiche reutilise les donnees, calculs de progression et workflows existants ; aucune nouvelle table ni migration n'est necessaire.
- L'avancement du PTA consolide les resultats officiels des actions par ponderation des cibles ; les cibles non configurees sont exclues et le retard respecte le seuil de realisation de chaque action.
- Les relations sont chargees avant le rendu et la vue Blade n'execute aucune requete metier.

## 51. Poste de travail Action et Suivi

### 51.1 Commandes et lecture par profil

- La liste Actions conserve ses tableaux, filtres et modes de lecture existants.
- Chaque ligne du tableau principal expose uniquement `Faire le suivi` et `Report de l'action`, quel que soit le profil lecteur.
- Le parametrage, la modification structurelle et les sous-actions restent geres depuis le PTA ; aucune commande `Modifier` ou `Supprimer` n'est affichee dans la ligne Action.
- La fiche de suivi affiche la prochaine intervention prioritaire selon le profil et l'etat : saisie, correction, visa du chef, controle final, traitement d'un report ou consultation.
- Le rattachement PAS, axe strategique, objectif strategique, PAO, objectif operationnel et PTA est visible dans le poste de traitement.
- Les ancres Validation, Avancement, Controle et Sous-actions ouvrent une zone existante et ne pointent plus vers un emplacement absent.

### 51.2 Execution et securite des transitions

- Une action non parametree, suspendue, annulee, terminee ou cloturee ne peut pas recevoir une nouvelle saisie d'execution.
- Une action soumise est gelee jusqu'a une demande de correction ou une decision finale.
- Une sous-action est modifiable uniquement aux etats `non_soumise` et `rejetee`.
- Une sous-action soumise ou validee refuse toute mise a jour directe, y compris par requete forgee.
- Le chef ne peut decider que sur une sous-action effectivement soumise et une decision deja rendue ne peut pas etre rejouee.
- Les statuts `soumise_controle`, `correction_controle` et `validee_controle` sont autorises par la contrainte PostgreSQL de l'action.

### 51.3 Discussion, reports et perimetre

- La publication d'un commentaire conserve le formulaire Web avec validation, autorisation et protection CSRF.
- Le rafraichissement du fil utilise la route API versionnee et construit les nouveaux elements avec `textContent`, sans injection de contenu utilisateur dans `innerHTML`.
- Un agent affecte uniquement a une sous-action peut consulter le suivi et son journal.
- Cet agent peut demander un report uniquement pour sa propre sous-action ; l'action principale et les sous-actions d'autres agents restent interdites cote serveur.
- Le report conserve la piece justificative obligatoire et le circuit Chef de service, Controleur, DG ou Chef Planification, puis application de la date par un controleur.

## 52. Circuit de financement des actions

### 52.1 Soumission du dossier

- Un besoin de financement cree dans le PTA commence a l'etat `pre_signale_daf` et reste dans la file du RMO.
- Seul un RMO de l'action peut confirmer la source, la note de transmission et soumettre le dossier a la DAF.
- Une piece initiale est obligatoire. Apres une demande de complement ou un rejet DAF, une nouvelle piece corrective est obligatoire pour resoumettre.
- La soumission place le dossier a l'etat `soumis_daf`, horodate l'envoi, notifie la DAF et cree un evenement d'audit.

### 52.2 Instruction DAF et decision DG

- Seule une direction dont le code est `DAF` peut instruire un dossier `soumis_daf`.
- La DAF peut transmettre un avis favorable a la DG, demander un complement au RMO ou rejeter le dossier avec motivation.
- Un avis favorable fixe le montant retenu et la reference, puis place le dossier a l'etat `transmis_dg`.
- Seul le profil DG peut accorder ou refuser definitivement un dossier transmis.
- Les decisions DAF et DG sont verrouillees en base ; une decision perimee ou rejouee est refusee sans modifier le resultat.
- Une action suspendue, annulee ou rattachee a un PTA cloture ou archive refuse toute nouvelle transition financiere.

### 52.3 Pilotage, preuves et securite

- Le poste financier affiche les dossiers chez le RMO, a instruire par la DAF, en decision DG, accordes et refuses.
- Les taches personnelles imputent le delai de preparation au RMO, l'instruction a la DAF et la decision finale a la DG.
- Chaque transition produit un journal d'action, un audit et les notifications du prochain acteur.
- Les pieces sont controlees par la politique documentaire, stockees de facon securisee et supprimees si la transaction metier echoue.
- La route historique permettant a la DAF de fixer directement un resultat final retourne `410 Gone`.
- Seuls `valide_dg`, `rejete_dg` et `non_requis` sont des etats financiers terminaux pour les controles de cloture.

## 53. Vues portefeuille exhaustives des actions

### 53.1 Separation entre tableau et visualisations

- Le tableau principal reste pagine de 15 a 100 actions afin de conserver une lecture administrative rapide.
- Les vues Kanban, Calendrier et Gantt utilisent toutes les actions du perimetre filtre, independamment de la page courante du tableau.
- Un changement de page du tableau ne retire donc aucune action d'une visualisation de portefeuille.
- Une valeur de mode d'affichage inconnue est neutralisee et retourne vers la liste paginee.

### 53.2 Coherence, securite et performance

- Le jeu exhaustif reutilise exactement le scope organisationnel, l'exercice actif, la vue metier, la recherche, les filtres et le tri de la liste.
- Une action hors direction, service ou delegation ne peut apparaitre dans aucune visualisation.
- Le chargement exhaustif est execute uniquement pour Kanban, Calendrier et Gantt ; la liste ne charge que sa page courante.
- Les visualisations selectionnent uniquement les colonnes et relations necessaires a leur rendu afin d'eviter les compteurs et KPI couteux du tableau.
- Kanban annonce le nombre d'actions du perimetre filtre, Calendrier le nombre d'echeances du mois et Gantt le nombre d'actions planifiees.
- Aucune table, migration, transition, notification ou regle de date n'est modifiee par cette evolution.

## 54. Cycle de vie resilient des graphiques du tableau de bord

### 54.1 Montage conditionne par la visibilite

- Un graphique Chart.js n'est monte que lorsque son conteneur appartient au document, est visible et possede une largeur et une hauteur exploitables.
- Le montage d'un graphique place dans un onglet masque est differe sans detruire son rendu ou son contenu de repli.
- L'activation de l'onglet relance le rendu et instancie alors les graphiques avec leurs dimensions finales.

### 54.2 Plugins et redimensionnement

- Le plugin d'annotation est desactive par defaut et active uniquement par les graphiques qui declarent un seuil metier.
- Les graphiques Chart.js et Plotly masques sont exclus du cycle de redimensionnement.
- Une erreur de redimensionnement est isolee au graphique concerne et ne bloque pas le rendu des autres composants du tableau de bord.
- Cette correction ne modifie aucune donnee metier, autorisation, route, migration ou regle de calcul.

## 55. File de travail personnelle exhaustive

### 55.1 Perimetre et priorisation

- Le module `Mes taches` reste accessible a tous les profils et agrege uniquement les travaux que les regles metier attribuent a l'utilisateur connecte.
- Les executions, corrections, validations, controles, financements, alertes, demandes de suppression et arbitrages de modification conservent leurs scopes serveur d'origine.
- La collecte ne tronque plus silencieusement chaque source a 40, 60, 80 ou 100 elements ; toutes les taches autorisees alimentent la file paginee.
- Le calcul multi-source reste mis en cache pendant 60 secondes et la version analytique invalide ce cache apres une evolution metier.
- L'ordre par defaut place les retards, puis les urgences et les echeances les plus proches avant les autres taches.

### 55.2 Recherche, vues et pagination

- La recherche porte sur le type de tache, le sujet, le contexte et le responsable, sans dependance aux accents ou a la casse.
- Les vues `Toutes`, `En retard`, `Sous 24 h`, `Critiques` et `Sans echeance` utilisent les delais calcules par le service de taches.
- Un filtre par famille separe execution, corrections, validations, financements, alertes et decisions.
- Les tris par priorite, echeance et reception recente sont disponibles avec une pagination de 15, 25 ou 50 lignes.
- Les valeurs de filtre inconnues sont neutralisees et ne peuvent ni elargir le perimetre ni provoquer une erreur de page.

### 55.3 Actes de validation

- Le chef peut valider ou renvoyer une action ou une sous-action directement depuis sa file lorsque le service expose explicitement cette capacite.
- Un renvoi sans motif est refuse et ne modifie pas le statut de la saisie.
- Une decision issue de `Mes taches` revient dans la file apres traitement ; une decision issue de la fiche Action conserve son retour vers la fiche.
- Les autorisations, verrous de statut, notifications et audits existants restent executes par le workflow Action.
- Aucune table, migration, nouvelle permission ou nouvelle transition n'est introduite par cette refonte.

## 56. Centre de notifications et d'alertes exhaustif

### 56.1 Boite de notifications

- Les notifications metier et les alertes techniques restent separees afin qu'une action de lecture globale ne modifie jamais l'autre famille.
- La synthese affiche le volume total, les elements lus, les elements non lus et les notifications prioritaires du seul utilisateur connecte.
- La recherche sans sensibilite a la casse ou aux accents porte sur le titre, le message, le module et le niveau.
- Les filtres par etat, niveau et module s'appliquent avant une pagination configurable de 15, 25 ou 50 lignes.
- L'ouverture marque la notification comme lue puis accepte uniquement une destination interne a l'application.

### 56.2 Alertes actives et historique

- Le centre charge toutes les alertes autorisees du perimetre avant d'appliquer la recherche, le niveau, l'origine, l'etat et la pagination.
- Aucun plafond de 100 elements ne peut masquer une alerte ancienne dans le centre ou l'exclure du marquage global.
- Les alertes actives et les instantanes deja lus disposent de vues distinctes et paginees.
- L'historique conserve le titre, le message, le niveau, l'origine, le perimetre, la destination et la date de lecture.
- Le marquage global enregistre les instantanes par lot et conserve l'unicite par utilisateur et empreinte.

### 56.3 Autorisation et robustesse

- L'onglet Alertes exige simultanement les permissions de lecture Planification et Alertes ; un parametre d'URL ne contourne pas ce controle.
- Les scopes existants de direction, service, delegation et lecture globale s'appliquent avant tout filtrage d'interface.
- Les filtres inconnus ou transmis sous forme de tableaux sont neutralises sans erreur et sans elargir le perimetre.
- Le menu deroulant global conserve une liste courte, tandis que le centre dedie fournit la consultation exhaustive.
- Cette refonte n'ajoute aucune table, permission, dependance ou transition metier.

## 57. Journal d'audit et tracabilite administrative

### 57.1 Consultation et perimetre

- Le journal reste en lecture seule et ne peut etre consulte que par un utilisateur possedant la permission sensible `audit.read`.
- La permission ouvre un perimetre global de tracabilite ; aucun parametre d'URL ne permet a un profil non autorise d'acceder a la page, a l'API ou a l'export.
- Les vues `Tous les evenements`, `Dernieres 24 h`, `Interventions`, `Sensibles` et `Organisation` appliquent des criteres serveur communs.
- La recherche porte sur le module, l'action, le type d'entite, l'adresse IP, le nom et l'adresse electronique de l'auteur.
- Les modules, actions, auteurs et types d'entites disponibles sont proposes sous forme de listes issues du journal existant.

### 57.2 Detail et protection des informations

- Chaque entree restitue l'horodatage, l'auteur, la categorie, le module, l'action, l'entite, l'adresse IP et l'environnement client.
- Les valeurs avant et apres sont consultables sans modifier la trace enregistree.
- Les champs dont le nom designe un mot de passe, un jeton, un secret, une cle API, une autorisation ou un cookie sont remplaces par `[MASQUE]` dans le Web, l'API et l'export.
- Les champs modifies sont calcules a partir des instantanes expurges et presentes sans executer de contenu utilisateur.
- Les liens vers PAS, PAO, PTA, Action et demande de report utilisent les routes nommees existantes ; les evenements de suppression ou d'archivage ne proposent pas de lien potentiellement obsolete.

### 57.3 Export, robustesse et performance

- L'export CSV reprend exactement les filtres et le tri actifs, utilise UTF-8 et separe les colonnes par un point-virgule.
- Les cellules commencant par un caractere de formule tableur sont neutralisees avant l'ecriture du fichier.
- L'export parcourt le journal par lots afin de ne pas charger tout l'historique en memoire.
- Les parametres transmis sous forme de tableaux, les dates invalides et les identifiants non positifs sont neutralises sans erreur et sans elargissement de droit.
- Les plages de dates utilisent les bornes horaires completes et les index `created_at/id` et `user_id/created_at` accelerent la pagination et les recherches courantes.
- La migration d'index est reversible et ne transforme, ne supprime ni ne renumerote aucune entree historique.

## 58. Referentiel organisationnel et securite des comptes

### 58.1 Consultation et perimetre

- Les ecrans Directions, Services et Utilisateurs partagent une navigation, des filtres normalises, un tri et une pagination de 15, 30, 50 ou 100 lignes.
- Les profils globaux consultent le perimetre agence ; les profils direction et service restent strictement limites par les rattachements du compte connecte.
- Un utilisateur sans rattachement et sans portee globale ne recoit aucune donnee par defaut.
- Les parametres transmis sous forme de tableaux, les identifiants non positifs et les valeurs de tri inconnues sont neutralises sans erreur et sans elargir le perimetre.
- L'API reutilise les memes requetes de perimetre que le Web tout en conservant ses permissions d'acces existantes.

### 58.2 Sante des comptes

- L'annuaire distingue les comptes actifs, inactifs, suspendus, en attente de renouvellement de mot de passe et dont le rattachement est incomplet.
- La recherche porte sur le nom, l'adresse electronique, le matricule et la fonction.
- Les roles personnalises sont filtres par leur code effectif, tandis que les roles standards excluent les comptes disposant d'un role personnalise.
- La fiche synthetique affiche le role, la direction, le service ou l'unite DG, la fonction, le matricule et l'etat operationnel.
- Les motifs de suppression restent obligatoires et les controles d'impact existants continuent de bloquer toute suppression dangereuse.

### 58.3 Identifiants temporaires

- Aucun mot de passe initial commun ou previsible n'est utilise pour une creation ou une reinitialisation administrative.
- Un mot de passe aleatoire conforme a la politique active est genere lorsqu'aucun mot de passe n'est saisi lors d'une creation unitaire.
- Les mots de passe temporaires sont affiches une seule fois dans la session administrative, ne sont jamais journalises en clair et imposent un renouvellement a la prochaine connexion.
- Une reinitialisation de masse genere un mot de passe distinct pour chaque utilisateur et est limitee a cent comptes par operation.
- Les changements de mot de passe administratifs revoquent les jetons ou sessions applicables et forcent le renouvellement.
- Un import CSV doit fournir un mot de passe conforme pour chaque nouveau compte ; une ligne sans mot de passe ou avec un mot de passe invalide est ignoree.

### 58.4 Exports

- Chaque liste propose un export CSV UTF-8 qui reprend les filtres et le perimetre organisationnel actifs.
- Les exports ne contiennent ni hash, ni jeton, ni mot de passe, ni secret de session.
- Les cellules commencant par un caractere de formule tableur sont neutralisees avant ecriture.
- Les lignes sont parcourues par lots afin de limiter la consommation memoire.
