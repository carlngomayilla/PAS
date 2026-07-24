# Cahier des charges

Application ANBG e-Pilotage PAS / PAO / PTA

Date de reference : 2026-07-08

Source d'analyse : code Laravel local, routes, modeles, migrations, services, tests et documentation projet.

## 1. Contexte

L'ANBG dispose d'un besoin de pilotage centralise de ses plans strategiques, operationnels et annuels. L'application e-Pilotage PAS / PAO / PTA repond a ce besoin en structurant la planification, l'execution, le controle, le reporting et la gouvernance des actions.

Le code analyse montre une application web Laravel couvrant :

- la chaine PAS, PAO et PTA ;
- le suivi d'execution des actions ;
- le controle du PTA ;
- les indicateurs et mesures KPI ;
- les justificatifs ;
- les exports et rapports ;
- les imports Excel et imports assistes par IA ;
- les alertes, notifications et taches ;
- l'administration et l'audit.

## 2. Finalite du projet

La finalite du projet est de fournir une plateforme fiable, securisee et exploitable par les differents profils de l'ANBG pour piloter les plans d'action et suivre leur niveau de realisation.

L'application doit permettre a chaque acteur de disposer d'une information a jour, tracable et consolidee, tout en reduisant les manipulations manuelles et les fichiers disperses.

## 3. Objectifs generaux

Les objectifs generaux sont :

- centraliser la gestion du PAS, PAO et PTA ;
- harmoniser la saisie des actions, sous-actions et indicateurs ;
- faciliter le controle des donnees PTA ;
- suivre l'execution des actions par service, direction et exercice ;
- mesurer la performance par KPI ;
- automatiser les alertes et notifications ;
- produire des rapports et exports officiels ;
- securiser les acces par role et perimetre ;
- tracer les operations sensibles ;
- faciliter l'import des donnees depuis Excel ou par assistance IA ;
- fournir une API exploitable par d'autres systemes autorises.

## 4. Beneficiaires et utilisateurs

Les beneficiaires principaux sont :

- Direction generale ;
- cabinet ;
- directions ;
- services ;
- planification ;
- SCIQ et suivi global ;
- administrateurs ;
- super-administrateurs ;
- auditeurs et lecteurs autorises.

Les utilisateurs interviennent selon leur role, leur service, leur direction et les permissions configurees.

## 5. Perimetre du projet

### 5.1 Inclus dans le perimetre

Le projet couvre :

- authentification et gestion de profil ;
- administration des utilisateurs, roles et permissions ;
- gestion des referentiels ;
- parametrage des exercices ;
- gestion du PAS ;
- gestion du PAO ;
- gestion du PTA ;
- gestion des actions et sous-actions ;
- gestion des indicateurs et mesures ;
- controle PTA avec edition en ligne ;
- suivi des avancements ;
- demandes de suppression ;
- demandes de report d'echeance ;
- gestion des financements d'action ;
- gestion des justificatifs ;
- alertes et notifications ;
- tableaux de bord ;
- recherche globale ;
- reporting et exports ;
- imports Excel ;
- imports assistes par IA ;
- generation de rapports IA ;
- audit et gouvernance ;
- super-administration ;
- API REST versionnee.

### 5.2 Hors perimetre

Sont hors perimetre sauf decision d'evolution :

- comptabilite generale complete ;
- paie ;
- gestion RH complete ;
- GED avancee independante ;
- systeme BI externe complet ;
- execution automatique de decisions IA sans validation humaine ;
- integration obligatoire avec tous les systemes tiers de l'organisation.

## 6. Besoins fonctionnels

### 6.1 Authentification et securite utilisateur

L'application doit permettre :

- connexion par identifiants ;
- deconnexion ;
- reinitialisation du mot de passe ;
- consultation et modification du profil ;
- protection des routes privees ;
- application des roles et permissions ;
- conservation d'un historique de mots de passe si configure ;
- gestion des delegations d'acces.

### 6.2 Referentiels

L'application doit permettre d'administrer :

- directions ;
- services ;
- unites ;
- utilisateurs ;
- exercices ;
- objectifs operationnels ;
- roles ;
- permissions ;
- parametres utiles a la planification et au reporting.

### 6.3 PAS

Le systeme doit permettre :

- creation d'un PAS ;
- gestion des axes strategiques ;
- gestion des objectifs strategiques ;
- consultation et modification selon droits ;
- suivi du statut ;
- rattachement aux PAO ;
- export ou restitution dans les rapports.

### 6.4 PAO

Le systeme doit permettre :

- creation d'un PAO ;
- rattachement au PAS ;
- gestion des axes et objectifs operationnels ;
- consultation par direction ou perimetre ;
- modification selon workflow ;
- alimentation du PTA.

### 6.5 PTA

Le systeme doit permettre :

- creation d'un PTA ;
- rattachement a un exercice, une direction, un service ou un objectif operationnel ;
- gestion des actions ;
- gestion des sous-actions ;
- parametrage des indicateurs ;
- validation ou verrouillage selon workflow ;
- export des donnees.

### 6.6 Controle officiel du PTA

Le systeme doit fournir une page de controle du PTA permettant :

- affichage sous forme de tableau ;
- filtrage par exercice, direction, service et objectif operationnel ;
- edition directe des cellules autorisees ;
- modification rapide sans redirection automatique ;
- sauvegarde des corrections ;
- affichage clair des cellules non renseignees ;
- export PDF et Excel ;
- acces direct a la fiche PTA par bouton dedie.

Les colonnes minimales attendues sont :

- Action ;
- Sous-action ;
- Indicateur de mesure ;
- RMO ;
- Seuil ;
- Echeance ;
- Observation ;
- Commandes.

La colonne Indicateur de mesure doit afficher :

- le type d'indicateur ;
- la quantite a realiser pour les indicateurs quantitatifs ;
- les livrables attendus pour les indicateurs non quantitatifs ;
- les deux informations pour les indicateurs mixtes.

Le mot "cible" doit etre retire de l'application au profit des libelles :

- seuil ;
- quantite a realiser ;
- livrables attendus.

### 6.7 Actions et sous-actions

Le systeme doit permettre :

- creation ou import d'actions ;
- consultation des actions ;
- modification selon role et statut ;
- rattachement a un PTA ;
- attribution de responsables ;
- definition des echeances ;
- suivi de l'avancement ;
- ajout de commentaires ;
- gestion de sous-actions ;
- rattachement d'indicateurs ;
- gestion des justificatifs ;
- journalisation des changements.

### 6.8 Indicateurs et KPI

Le systeme doit permettre :

- creation d'indicateurs ;
- choix du type d'indicateur ;
- parametrage des champs attendus selon le type ;
- renseignement de la quantite a realiser ;
- renseignement des livrables attendus ;
- renseignement du seuil ;
- saisie des mesures ;
- calcul ou affichage de la progression ;
- alimentation des tableaux de bord et rapports.

### 6.9 Justificatifs

Le systeme doit permettre :

- depot de fichiers justificatifs ;
- rattachement a une action ou mesure ;
- consultation selon droits ;
- suppression controlee ;
- exploitation dans le suivi et la validation.

### 6.10 Demandes de suppression

Le systeme doit permettre :

- demande de suppression par un utilisateur autorise ;
- saisie d'un motif ;
- workflow d'approbation ou de rejet ;
- notification des parties concernees ;
- journalisation de la decision.

### 6.11 Demandes de report d'echeance

Le systeme doit permettre :

- demande de report d'echeance ;
- ciblage d'une action ou d'une sous-action ;
- justification detaillee et piece justificative obligatoire ;
- avis du Chef de service, puis du controleur SCIQ ou Planification ;
- decision finale du DG ou du Chef Planification ;
- application de la date approuvee exclusivement par un controleur ;
- reprise du meme dossier par le demandeur lorsqu'un complement est exige ;
- file centralisee avec vues "A traiter" et "Mes demandes", recherche et acces direct au dossier ;
- fiche dediee de traitement avec progression du circuit et formulaire limite a la decision autorisee ;
- badge de navigation, prefiltrage par role et delegation, recherche, pagination et tri stable ;
- verrouillage des dates dans les formulaires, l'API et les imports Excel ou IA ;
- notification, historique des revisions, telechargement des pieces successives et audit.

### 6.12 Financement

Le systeme doit permettre :

- saisie des donnees de financement ;
- suivi des montants ;
- validation selon les profils ;
- restitution dans les tableaux et rapports.

### 6.13 Alertes et notifications

Le systeme doit permettre :

- generation d'alertes sur les echeances, retards et demandes ;
- affichage des notifications ;
- marquage comme lu ;
- envoi d'alertes par les canaux configures ;
- consultation depuis le tableau de bord ou l'espace de travail.

### 6.14 Reporting et exports

Le systeme doit permettre :

- generation de rapports de synthese ;
- export PDF ;
- export Excel ;
- generation de documents Word si requis ;
- telechargement des fichiers ;
- personnalisation via modeles d'export ;
- production de rapports officiels de controle.

### 6.15 Imports Excel

Le systeme doit permettre :

- televersement de fichiers Excel ;
- previsualisation ;
- mapping de colonnes ;
- detection d'erreurs ;
- import des lignes valides ;
- suivi des imports ;
- consultation des erreurs.

### 6.16 Imports assistes par IA

Le systeme doit permettre :

- depot d'un document ou fichier ;
- analyse assistee par IA ;
- extraction de donnees proposees ;
- correction et validation humaine ;
- enregistrement des donnees validees ;
- journalisation du lot et des lignes.

### 6.17 Rapports IA

Le systeme doit permettre :

- generation de rapports assistes par IA ;
- consultation des rapports ;
- telechargement ou export ;
- collecte de feedback ;
- conservation de connaissances ou exemples d'entrainement.

### 6.18 Gouvernance et audit

Le systeme doit permettre :

- consultation du journal d'audit ;
- tracabilite des operations sensibles ;
- suivi des imports ;
- suivi des modifications de configuration ;
- gestion de l'archivage ;
- diagnostic d'administration.

### 6.19 API

Le systeme doit exposer une API versionnee permettant :

- authentification ;
- consultation des ressources autorisees ;
- manipulation de certaines entites selon droits ;
- acces aux referentiels, plans, actions, KPI, mesures, audit, reporting et alertes.

## 7. Exigences non fonctionnelles

### 7.1 Securite

L'application doit garantir :

- authentification robuste ;
- controle d'acces par role et permission ;
- limitation par perimetre organisationnel ;
- protection des routes ;
- protection des fichiers ;
- journalisation des actions sensibles ;
- validation des donnees entrantes ;
- absence d'exposition non autorisee des donnees.

### 7.2 Performance

L'application doit permettre une utilisation fluide des tableaux et formulaires.

Attentes :

- chargement raisonnable des listes ;
- filtres efficaces ;
- edition directe dans les tableaux lorsque necessaire ;
- pagination ou limitation des gros volumes ;
- exports capables de traiter les donnees attendues ;
- optimisation des requetes critiques.

### 7.3 Ergonomie

L'application doit etre simple a utiliser par des profils metier.

Attentes :

- interface claire ;
- libelles metier coherents ;
- boutons regroupes de maniere logique ;
- messages d'erreur comprensibles ;
- navigation rapide ;
- moins de redirections inutiles ;
- affichage clair des donnees manquantes.

### 7.4 Disponibilite

La plateforme doit etre disponible pendant les plages d'utilisation de l'organisation.

Attentes :

- deploiement stable ;
- sauvegardes ;
- reprise en cas d'incident ;
- supervision des erreurs ;
- journaux exploitables.

### 7.5 Maintenabilite

Le code doit rester maintenable.

Attentes :

- respect des conventions Laravel ;
- services metier separes ;
- tests fonctionnels ;
- migrations claires ;
- documentation projet ;
- absence de duplication excessive.

### 7.6 Tracabilite

Toutes les operations sensibles doivent etre auditables :

- utilisateur ;
- date ;
- action ;
- entite ;
- contexte ;
- decision.

### 7.7 Compatibilite

L'application doit etre utilisable dans un navigateur moderne sur ordinateur. Les vues doivent rester consultables sur des tailles d'ecran raisonnables, notamment pour les tableaux.

## 8. Contraintes techniques

### 8.1 Socle applicatif

Le projet utilise :

- PHP 8.4 ;
- Laravel 13 ;
- Laravel Sanctum ;
- Laravel Boost ;
- Laravel Pail ;
- PHPUnit 12 ;
- Laravel Pint ;
- Tailwind CSS 4 ;
- Vite ;
- Laravel Echo selon les besoins temps reel.

### 8.2 Bibliotheques fonctionnelles

Le code contient notamment :

- generation PDF ;
- exports Excel ;
- generation ou manipulation Word ;
- parsing PDF ;
- fonctions IA ;
- notifications email ;
- API REST.

### 8.3 Base de donnees

La base doit stocker :

- utilisateurs et rattachements ;
- referentiels ;
- plans PAS, PAO, PTA ;
- actions et sous-actions ;
- indicateurs et mesures ;
- justificatifs ;
- workflows ;
- demandes ;
- alertes ;
- audits ;
- imports ;
- rapports IA ;
- parametres de plateforme.

### 8.4 Fichiers

L'application doit pouvoir stocker et servir de facon securisee :

- justificatifs ;
- fichiers importes ;
- exports generes ;
- rapports ;
- documents temporaires.

### 8.5 Emails et notifications

L'application peut utiliser une integration de messagerie pour les notifications.

Attentes :

- configuration SMTP ou fournisseur equivalent ;
- templates de messages ;
- journalisation ou suivi des envois importants.

## 9. Architecture fonctionnelle cible

L'architecture fonctionnelle s'organise en couches :

- interface web ;
- controles d'acces ;
- controllers ;
- form requests ;
- services metier ;
- modeles Eloquent ;
- base de donnees ;
- systeme de fichiers ;
- jobs, notifications et exports ;
- API REST.

Les services metier identifiables couvrent notamment :

- calcul d'avancement ;
- suivi PTA ;
- imports ;
- exports ;
- alertes ;
- gouvernance ;
- securite ;
- workflow ;
- IA ;
- tableaux de bord.

## 10. Donnees et qualite

### 10.1 Qualite des donnees attendue

Les donnees doivent etre :

- completes ;
- coherentes ;
- rattachees au bon exercice ;
- rattachees a la bonne direction ou au bon service ;
- non dupliquees lorsque l'unicite est requise ;
- auditables ;
- exploitables dans les exports.

### 10.2 Donnees obligatoires principales

Selon l'objet :

- libelle ;
- exercice ;
- rattachement organisationnel ;
- responsable ;
- echeance ;
- indicateur ;
- type d'indicateur ;
- seuil ou quantite a realiser ;
- livrables attendus si applicable.

### 10.3 Donnees manquantes

Les donnees manquantes doivent etre visibles dans les tableaux de controle afin de permettre leur correction rapide.

## 11. Regles de gestion prioritaires

### 11.1 Regle de rattachement

Une action doit etre rattachee a un PTA et a son perimetre operationnel.

### 11.2 Regle de controle PTA

Le controleur doit pouvoir corriger les informations de controle directement dans le tableau lorsqu'il dispose des droits.

### 11.3 Regle sur les indicateurs

Le type d'indicateur conditionne les champs a renseigner :

- quantitatif : quantite a realiser ;
- non quantitatif : livrables attendus ;
- mixte : quantite a realiser et livrables attendus.

### 11.4 Regle sur le vocabulaire

Le terme "cible" est retire de l'application au profit de "seuil", "quantite a realiser" et "livrables attendus".

### 11.5 Regle de validation

Les objets soumis a workflow ne peuvent pas etre modifies par n'importe quel profil a n'importe quel statut.

### 11.6 Regle d'audit

Toute modification sensible doit etre tracee.

### 11.7 Regle IA

L'IA assiste l'utilisateur, mais la validation finale reste humaine.

## 12. Interfaces et integrations

### 12.1 Interface web

Interface principale pour :

- saisie ;
- consultation ;
- controle ;
- export ;
- administration.

### 12.2 API REST

Interface technique pour :

- authentification ;
- consultation ;
- integration possible avec des systemes tiers ;
- exposition controlee des donnees.

### 12.3 Exports bureautiques

Formats attendus :

- PDF ;
- Excel ;
- Word selon les rapports.

### 12.4 Intelligence artificielle

L'IA est utilisee pour :

- assistance a l'import ;
- generation de rapports ;
- exploitation de connaissances ;
- feedback et amelioration.

### 12.5 Messagerie

La messagerie sert aux notifications ou alertes selon configuration.

## 13. Livrables attendus

Les livrables fonctionnels et techniques sont :

- application web operationnelle ;
- base de donnees structuree ;
- interface de connexion ;
- modules PAS, PAO, PTA ;
- page de controle PTA ;
- modules actions, sous-actions et KPI ;
- modules imports ;
- modules rapports et exports ;
- module d'administration ;
- module audit ;
- API versionnee ;
- tests fonctionnels ;
- documentation fonctionnelle ;
- cahier des charges ;
- documentation de deploiement si demandee ;
- guide utilisateur si demande.

## 14. Decoupage en lots propose

### Lot 1 : socle et securite

- authentification ;
- roles et permissions ;
- profils ;
- referentiels ;
- exercices ;
- audit de base.

### Lot 2 : planification

- PAS ;
- PAO ;
- PTA ;
- objectifs ;
- rattachements ;
- statuts.

### Lot 3 : execution et controle

- actions ;
- sous-actions ;
- indicateurs ;
- mesures ;
- justificatifs ;
- controle PTA ;
- edition directe.

### Lot 4 : workflows

- validations ;
- verrouillages ;
- reports d'echeance ;
- suppressions ;
- financements ;
- notifications.

### Lot 5 : reporting et exports

- tableaux de bord ;
- syntheses ;
- PDF ;
- Excel ;
- Word ;
- modeles d'export.

### Lot 6 : imports et IA

- imports Excel ;
- imports IA ;
- audit des imports ;
- rapports IA ;
- feedback IA.

### Lot 7 : administration avancee

- super-administration ;
- parametres plateforme ;
- workflows ;
- snapshots ;
- diagnostics ;
- maintenance.

### Lot 8 : recette, performance et production

- tests ;
- corrections ;
- optimisation ;
- securisation ;
- sauvegardes ;
- deploiement.

## 15. Criteres d'acceptation

### 15.1 Socle

- Un utilisateur peut se connecter et se deconnecter.
- Les routes protegees ne sont pas accessibles sans session.
- Les roles limitent les fonctions visibles.

### 15.2 Referentiels

- Les directions, services, utilisateurs et exercices sont consultables.
- Les modifications respectent les permissions.

### 15.3 PAS / PAO / PTA

- Les plans peuvent etre crees et consultes.
- Les relations entre niveaux sont conservees.
- Les statuts sont visibles.

### 15.4 Controle PTA

- Les colonnes attendues sont visibles.
- Les cellules autorisees sont modifiables sur place.
- Le clic sur une cellule ne redirige pas vers une ancienne fenetre de detail.
- Les boutons sont regroupes dans la colonne finale.
- Le bouton de fiche PTA ouvre la fiche complete.
- Le filtre par objectif operationnel fonctionne.

### 15.5 Indicateurs

- Le type d'indicateur est visible.
- Les champs attendus changent selon le type.
- Le seuil est separe de la quantite a realiser.
- Le mot "cible" n'apparait plus comme libelle applicatif.

### 15.6 Actions

- L'avancement peut etre renseigne.
- Les justificatifs peuvent etre rattaches.
- Les demandes de report et suppression suivent le workflow.

### 15.7 Reporting

- Les exports sont generes.
- Les donnees exportees correspondent aux filtres.
- Les rapports respectent les droits d'acces.

### 15.8 Imports

- Un fichier valide est importe.
- Un fichier invalide affiche les erreurs.
- Les imports IA necessitent une validation humaine.

### 15.9 Audit

- Les modifications sensibles sont visibles dans le journal.
- Les utilisateurs non autorises ne voient pas les journaux proteges.

## 16. Tests et recette

### 16.1 Tests automatises

Le projet doit maintenir des tests PHPUnit pour :

- authentification ;
- roles et permissions ;
- tableaux de bord ;
- PAS / PAO / PTA ;
- controle PTA ;
- actions ;
- KPI ;
- imports ;
- exports ;
- alertes ;
- notifications ;
- gouvernance ;
- securite.

### 16.2 Recette metier

La recette metier doit verifier :

- coherence des libelles ;
- ergonomie des tableaux ;
- exactitude des exports ;
- respect des workflows ;
- qualite des donnees importees ;
- visibilite des alertes ;
- pertinence des tableaux de bord.

### 16.3 Jeux de donnees

Les jeux de donnees de recette doivent couvrir :

- plusieurs directions ;
- plusieurs services ;
- plusieurs exercices ;
- actions completes ;
- actions incompletes ;
- indicateurs quantitatifs ;
- indicateurs non quantitatifs ;
- indicateurs mixtes ;
- demandes en attente ;
- imports en erreur ;
- utilisateurs avec droits differents.

## 17. Risques et mesures de maitrise

### 17.1 Qualite des donnees

Risque : donnees incompletes ou mal rattachees.

Mesures : validations, filtres de controle, cellules manquantes visibles, imports audites.

### 17.2 Complexite des droits

Risque : acces trop large ou trop restreint.

Mesures : roles clairs, tests de permissions, simulation admin, audit.

### 17.3 Performance des tableaux

Risque : lenteur sur gros volumes.

Mesures : filtres, pagination, optimisation des requetes, exports dedies.

### 17.4 Imports non fiables

Risque : erreurs de mapping ou donnees incoherentes.

Mesures : previsualisation, validation, rapport d'erreurs, audit.

### 17.5 IA

Risque : extraction ou rapport incorrect.

Mesures : validation humaine obligatoire, audit, feedback, affichage des incertitudes.

### 17.6 Securite

Risque : fuite ou modification non autorisee.

Mesures : permissions, policies, validation, journalisation, protection des fichiers.

### 17.7 Adoption utilisateur

Risque : maintien des fichiers paralleles.

Mesures : ergonomie, formation, exports officiels, controle PTA rapide.

## 18. Exploitation et maintenance

### 18.1 Exploitation

L'exploitation doit prevoir :

- environnement de production ;
- configuration applicative ;
- base de donnees ;
- stockage fichiers ;
- taches planifiees ;
- file d'attente si necessaire ;
- supervision ;
- sauvegardes ;
- procedure de restauration.

### 18.2 Maintenance corrective

La maintenance doit couvrir :

- correction d'anomalies ;
- mise a jour des workflows ;
- ajustement des permissions ;
- correction des exports ;
- correction des imports.

### 18.3 Maintenance evolutive

Les evolutions possibles :

- nouveaux rapports ;
- nouveaux indicateurs ;
- integrations externes ;
- amelioration de l'IA ;
- enrichissement du controle PTA ;
- tableaux de bord supplementaires.

### 18.4 Documentation

La documentation a maintenir :

- cahier de specifications fonctionnelles ;
- cahier des charges ;
- guide utilisateur ;
- guide administrateur ;
- documentation API ;
- notes de deploiement.

## 19. Gouvernance projet

### 19.1 Maitrise d'ouvrage

La maitrise d'ouvrage valide :

- besoins metier ;
- regles de gestion ;
- priorites ;
- criteres de recette ;
- arbitrages fonctionnels.

### 19.2 Maitrise d'oeuvre

La maitrise d'oeuvre realise :

- conception technique ;
- developpement ;
- tests ;
- integration ;
- deploiement ;
- maintenance.

### 19.3 Utilisateurs pilotes

Les utilisateurs pilotes doivent valider :

- parcours PAS / PAO / PTA ;
- controle PTA ;
- imports ;
- exports ;
- tableaux de bord ;
- workflows de validation.

## 20. Hypotheses

Les hypotheses retenues sont :

- les utilisateurs disposent d'un navigateur moderne ;
- les roles sont definis par l'organisation ;
- les referentiels sont maintenus a jour ;
- les exercices sont parametres avant usage ;
- les imports sont relus avant validation ;
- les exports officiels sont produits depuis les vues prevues ;
- les donnees sensibles restent protegees par les droits.

## 21. Contraintes de mise en production

Avant mise en production, il faut verifier :

- configuration `.env` ;
- cle applicative ;
- connexion base de donnees ;
- migrations ;
- compte super-administrateur ;
- configuration email ;
- stockage public/prive ;
- permissions fichiers ;
- taches planifiees ;
- files d'attente ;
- build frontend ;
- sauvegardes ;
- tests automatises ;
- verification des routes sensibles.

## 22. Priorites fonctionnelles recommandees

Priorite 1 :

- authentification ;
- roles et permissions ;
- referentiels ;
- PAS / PAO / PTA ;
- actions ;
- controle PTA ;
- indicateurs ;
- exports essentiels.

Priorite 2 :

- workflows de suppression et report ;
- notifications ;
- justificatifs ;
- financements ;
- tableaux de bord avances.

Priorite 3 :

- imports IA ;
- rapports IA ;
- snapshots ;
- diagnostics avances ;
- integrations externes.

## 23. Synthese des exigences majeures

L'application doit permettre a l'ANBG de piloter l'ensemble de la chaine PAS, PAO et PTA dans un outil unique.

Les points critiques sont :

- fiabilite des donnees ;
- droits d'acces stricts ;
- controle PTA rapide et modifiable sur place ;
- indicateurs bien structures ;
- exports officiels ;
- audit complet ;
- imports controles ;
- tableaux de bord exploitables.

## 24. Annexes

Elements de code utilises pour etablir ce cahier des charges :

- `routes/web.php` ;
- `routes/api.php` ;
- dossier `app/Models` ;
- dossier `app/Services` ;
- dossier `app/Http/Controllers` ;
- dossier `app/Http/Requests` ;
- migrations de base de donnees ;
- vues Blade principales ;
- tests Feature ;
- documentation existante dans `docs/` ;
- `README.md` ;
- `GUIDE.md`.

## 25. Complement issu de la comparaison avec les documents Word

Deux documents Word ont ete compares avec le cahier des charges et le cahier de specifications generes depuis le code :

- `C:\Users\chris\OK\CDC_ePilotage_ANBG.docx` ;
- `C:\Users\chris\OK\CSF_ePilotage_ANBG.docx`.

Ces documents precisent principalement la refonte des tableaux de bord de l'application e-Pilotage PAS / PAO / PTA.

Ils doivent etre integres comme une evolution fonctionnelle, sans suppression des ecrans existants.

## 26. Orientation projet retenue

L'orientation retenue est la suivante :

- conserver l'application actuelle et ses modules ;
- conserver le design general et les composants existants ;
- conserver la page de suivi officiel du PTA ;
- conserver les syntheses ;
- conserver les graphiques ;
- conserver les vues detaillees ;
- enrichir les tableaux de bord par profil ;
- ameliorer la navigation PAS / PAO / PTA ;
- renforcer le moteur d'alerte ;
- simplifier la saisie d'avancement ;
- fiabiliser les donnees de suivi et de controle.

La refonte demandee ne doit pas etre consideree comme une destruction/remplacement de l'existant, mais comme une reorganisation progressive des ecrans de pilotage.

## 27. Principes directeurs complementaires

### 27.1 Densite progressive

Chaque tableau de bord doit afficher d'abord l'essentiel.

Exigence :

- 4 a 6 indicateurs cles maximum sur l'ecran d'accueil d'un profil ;
- les indicateurs additionnels doivent etre disponibles par onglets, graphiques, sections repliables ou vues detaillees ;
- la page de suivi PTA et les tableaux complets restent accessibles pour le travail de controle.

### 27.2 Coherence visuelle inter-niveaux

Les tableaux de bord doivent partager :

- meme logique de carte ;
- meme logique de couleur d'alerte ;
- meme style de badge ;
- meme comportement de clic ;
- meme logique de retour au niveau superieur.

Cette coherence doit respecter le design existant de l'application.

### 27.3 Exploration progressive

La navigation doit permettre de passer progressivement :

- du PAS vers le PAO ;
- du PAO vers le PTA ;
- du PTA vers les activites, directions, services ou actions ;
- d'une synthese vers une vue detaillee.

L'utilisateur ne doit pas etre redirige sans contexte.

### 27.4 Saisie minimale

La mise a jour courante de l'avancement d'une activite doit rester simple.

Champs attendus :

- pourcentage ;
- date ;
- commentaire court.

Les justificatifs et informations longues restent accessibles en complement, mais ne doivent pas alourdir la saisie rapide.

### 27.5 Alerte avant donnee brute

Les alertes doivent orienter rapidement l'utilisateur.

Regles :

- rouge pour critique ;
- orange pour vigilance ;
- vert pour conforme ;
- gris-bleu ou neutre pour information ;
- libelle ou icone obligatoire en plus de la couleur.

## 28. Exigences de tableaux de bord par profil

### 28.1 Direction generale

La Direction generale doit disposer :

- d'une vision consolidee PAS, PAO et PTA ;
- d'une lecture de la trajectoire 2026-2028 ;
- d'une synthese PTA annuelle ;
- d'alertes globales ;
- d'une comparaison entre directions ;
- d'une vue de conformite des rapports ;
- d'un acces aux details sans perdre la vue globale.

### 28.2 Directeur

Le Directeur doit disposer :

- du taux d'execution de sa direction ;
- des activites realisees et en retard ;
- du budget execute ;
- des rapports remis ;
- de la contribution de sa direction aux axes PAS ;
- d'une comparaison des services rattaches ;
- d'alertes limitees aux situations critiques ou de vigilance.

### 28.3 Chef de service

Le Chef de service doit disposer :

- du taux d'execution du service ;
- des activites du service ;
- des rapports produits ;
- du suivi PTA du service ;
- des indicateurs des agents ;
- des alertes du service ;
- des demandes de report a valider.

### 28.4 Agent

L'Agent doit disposer :

- de ses activites assignees ;
- de filtres par PTA, mois, trimestre et semestre ;
- de son taux de realisation ;
- de ses activites en retard ;
- de la saisie rapide d'avancement ;
- de la possibilite de demander un report d'echeance avec motif ;
- du calendrier mis a jour apres validation.

### 28.5 Planification

La Planification doit disposer :

- d'une vue consolidee transverse ;
- du suivi de la progression des axes PAS ;
- du controle de coherence PAS / PAO / PTA ;
- des activites PTA non rattachees a un objectif PAO ;
- des objectifs PAO sans activite PTA ;
- du pilotage des echeances de reporting ;
- de la page de controle PTA avec edition en ligne.

### 28.6 Suivi-evaluation, SCIQ et suivi global

Le suivi-evaluation et le SCIQ doivent disposer :

- d'indicateurs de qualite de la donnee ;
- des activites non mises a jour depuis 30 jours ;
- des indicateurs sans source de collecte ;
- des ecarts entre declaratif et verification ;
- des activites a 100% sans justificatif ;
- des modifications posterieures suspectes ;
- d'un acces detaille aux donnees concernees.

## 29. Exigences complementaires sur les alertes

Le projet doit prevoir un moteur d'alerte transversal.

Il doit permettre :

- parametrage de seuils par indicateur ;
- seuil de vigilance ;
- seuil critique ;
- historique des changements de statut ;
- filtrage des alertes par profil ;
- acces direct a l'objet concerne ;
- restitution par couleur et libelle ;
- respect de l'accessibilite.

Les alertes affichees aux profils Directeur et Chef de service doivent prioriser les situations critiques et de vigilance afin d'eviter la surcharge d'information.

## 30. Exigences complementaires sur les donnees

Les documents Word font apparaitre des besoins de donnees a confirmer dans le schema applicatif.

Donnees a maintenir ou completer :

- seuils d'alerte parametrables ;
- historique des niveaux d'alerte ;
- source de collecte des indicateurs ;
- justificatifs lies aux activites ;
- journal des modifications posterieures ;
- rattachement activite PTA vers objectif PAO ;
- table ou mecanisme de demande de report d'echeance ;
- niveau attendu pour les indicateurs de vision strategique.

Ces ajouts doivent etre compatibles avec les donnees historiques.

## 31. Contraintes de compatibilite avec l'existant

Les documents Word mentionnent une pile Laravel 12 / PHP 8.2 / PostgreSQL / Filament v3. Le code local audite est base sur Laravel 13, PHP 8.4, Blade/Tailwind et Sanctum.

La contrainte projet est donc :

- conserver le socle technique reel du code ;
- ne pas introduire Filament uniquement parce qu'il est mentionne dans les documents Word ;
- traduire les widgets Word/Filament en composants existants ou equivalents ;
- ne pas casser les routes, vues, services et tests existants ;
- maintenir les exports et vues officielles.

## 32. Criteres d'acceptation complementaires

Les criteres suivants completent la section 15.

- Chaque profil dispose d'un tableau de bord adapte a son niveau de responsabilite.
- Les tableaux de bord d'accueil ne depassent pas 4 a 6 indicateurs principaux.
- Les vues detaillees restent accessibles.
- La navigation PAS / PAO / PTA est progressive.
- Les alertes sont visibles, parametrees et comprehensibles.
- Les graphiques existants sont conserves ou reutilises.
- La synthese globale reste disponible.
- La page de suivi PTA reste disponible.
- Les profils planification et SCIQ conservent leurs capacites d'ajustement et de controle.
- La saisie agent est simplifiee.
- Le workflow de report d'echeance fonctionne de bout en bout.
- Aucune regression n'est constatee sur les modules existants.

## 33. Priorite d'implementation issue de la comparaison

Priorite immediate :

- conserver et stabiliser la page de suivi PTA ;
- finaliser les libelles "seuil", "quantite a realiser" et "livrables attendus" ;
- garantir l'edition en ligne pour planification et SCIQ ;
- documenter les tableaux de bord par profil ;
- ajouter les criteres de navigation PAS / PAO / PTA.

Priorite suivante :

- moteur d'alerte parametrable ;
- cartes de synthese limitees a 4 a 6 indicateurs ;
- drill-down avec fil d'Ariane ;
- indicateurs de qualite de donnee ;
- tableau de bord Agent simplifie ;
- workflow de report enrichi.

Priorite evolutive :

- historique avance des alertes ;
- comparaison graphique des directions et services ;
- rapports de conformite ;
- indicateurs de fiabilite et controle interne ;
- optimisation cache/performance des tableaux de bord.

## 34. Decision de refonte du tableau de bord

La vue essentielle simplifiee n'est pas retenue comme page d'accueil principale. Le tableau de bord conserve une lecture riche et progressive avec :

- un centre de pilotage adapte au profil : executif, administratif, directionnel, service ou operationnel ;
- quatre a six indicateurs de synthese stables, y compris lorsque leur valeur est nulle ;
- un flux a traiter regroupant les validations, les demandes de report d'echeance et les points critiques ;
- des acces directs vers le suivi PTA, les rapports, les taches et la file des reports d'echeance ;
- le maintien des onglets Synthese, Graphiques et Vue detaillee et de leurs tableaux existants.

Les demandes de report affichees dans le flux respectent le role et le perimetre de l'utilisateur. Le compteur actionnable correspond uniquement aux dossiers que cet utilisateur peut traiter a l'etape courante.

## 35. Regle de declinaison multi-axes du PAO

Le PAO est unique pour une direction et une annee. Il peut toutefois couvrir plusieurs axes et plusieurs objectifs strategiques appartenant au meme PAS.

- chaque objectif operationnel du PAO est rattache explicitement a un objectif strategique ;
- l'unicite du PAO actif par direction et par annee est garantie en base de donnees ;
- chaque objectif operationnel est transmis a un service de la direction du PAO ;
- tous les rattachements strategiques d'un meme PAO appartiennent au meme PAS ;
- l'echeance d'un objectif operationnel reste dans l'annee du PAO et ne depasse pas celle de son objectif strategique ;
- un objectif operationnel deja utilise par un PTA ou une action ne peut pas etre retire silencieusement ;
- les filtres Web et API retrouvent le PAO depuis n'importe quel objectif strategique couvert ;
- la liste PAO affiche la couverture strategique et les services destinataires reels.

Le champ d'objectif strategique principal reste conserve sur le PAO pour la compatibilite des donnees, exports et integrations existants. Les rattachements detailles portes par les objectifs operationnels constituent la reference pour la declinaison multi-axes.

## 36. Vue de declinaison progressive du PAS

La liste des PAS donne acces a une page d'exploration detaillee. Cette page conserve une synthese executive et permet d'ouvrir progressivement les niveaux strategiques et operationnels.

- le premier niveau presente la periode, le statut, le taux de couverture et six compteurs de structure ;
- les axes et objectifs strategiques restent visibles meme lorsqu'ils ne sont pas encore declines ;
- les PAO sont rattaches aux objectifs strategiques reellement couverts par leurs objectifs operationnels ;
- les objectifs non declines, les objectifs operationnels sans PTA et les PTA sans action sont signales explicitement ;
- les liens contextuels permettent d'ouvrir les PAO, PTA et actions deja filtres ;
- les profils globaux voient l'ensemble du PAS, les directions leur perimetre et les services uniquement leurs donnees operationnelles ;
- le filtrage et le refus des acces directs hors perimetre sont appliques cote serveur ;
- la construction de la hierarchie est centralisee dans un service applicatif et couverte par des tests fonctionnels.

## 37. Fiche de pilotage operationnel du PAO

Le PAO dispose d'une fiche de consultation riche qui relie directement la strategie annuelle aux traitements des actions.

- la fiche presente la direction, l'exercice, le statut, l'echeance et l'avancement moyen ;
- six indicateurs rendent visibles la couverture strategique, les services, les objectifs operationnels, les PTA, les retards et les reports actifs ;
- la hierarchie est regroupee par axe et objectif strategique, puis par objectif operationnel et PTA ;
- chaque action affiche son responsable, son echeance, son avancement et son report actif ;
- les seules commandes de la ligne action sont `Faire le suivi` et `Report d'echeance`, redirigees vers le workflow existant ;
- les objectifs sans PTA, les PTA sans action, les actions en retard, les actions a parametrer et les reports actifs sont signales ;
- les lecteurs globaux, directions et services recoivent uniquement les donnees de leur perimetre ;
- les agents conservent leurs parcours d'execution dedies ;
- aucun nouveau stockage ni nouveau workflow n'est cree pour cette fiche de lecture.

## 38. Fiche de pilotage administratif du PTA

Le PTA dispose d'une fiche de consultation dense qui relie le cadrage strategique, le parametrage des actions et leur traitement quotidien.

- la fiche presente le service, la direction, l'exercice, le statut, l'echeance de l'objectif operationnel et l'avancement moyen ;
- une chaine visible restitue le PAS, l'axe, l'objectif strategique, le PAO et l'objectif operationnel de rattachement ;
- six indicateurs rendent visibles les actions, les sous-actions, les parametrages incomplets, les retards, les reports actifs et les validations en attente ;
- le tableau administratif conserve les informations de parametrage, cible, RMO, calendrier, progression, preuves, validation et report ;
- les seules commandes de la ligne action restent `Faire le suivi` et `Report d'echeance` ;
- le report actif reste soumis au circuit chef de service, controle, decision finale et application par un controleur ;
- les anomalies de parametrage et le cas d'un PTA sans action sont signales explicitement ;
- les profils globaux, directions et services recoivent uniquement les PTA de leur perimetre ;
- les agents conservent leurs ecrans d'execution ;
- l'avancement consolide les cibles officielles des actions et le retard respecte leur seuil de realisation configure ;
- la fiche reutilise les donnees et services existants, sans migration ni nouveau workflow.

## 39. Poste de travail Action et Suivi

Le module Actions devient un poste de traitement operationnel et administratif, sans dupliquer le parametrage gere dans le PTA.

- le tableau principal conserve sa structure et limite chaque ligne aux commandes `Faire le suivi` et `Report de l'action` ;
- la fiche Action restitue le rattachement strategique complet et adapte la prochaine intervention au profil connecte ;
- les responsables saisissent ou corrigent, les chefs visent ou renvoient, et les controleurs rendent la decision finale ;
- les actions suspendues, annulees, terminees, cloturees ou en cours de validation refusent les saisies directes ;
- les sous-actions soumises ou validees sont protegees cote serveur contre la reecriture et les decisions rejouees ;
- les agents affectes a une sous-action disposent de la lecture du dossier et peuvent demander un report uniquement pour leur propre echeance ;
- la discussion utilise le formulaire Web pour publier et l'API versionnee pour rafraichir, sans inserer de contenu utilisateur par HTML dynamique ;
- PostgreSQL accepte tous les statuts du circuit de controle et la migration possede une strategie de retour vers les statuts historiques ;
- aucune date n'est modifiee en dehors du circuit de report approuve et seul un controleur applique la date finale.

## 40. Gouvernance du financement DAF et DG

Le financement d'une action suit un circuit unique, trace et non contournable.

- le RMO complete et soumet le dossier financier prepare dans le PTA ;
- la DAF instruit uniquement les dossiers officiellement soumis ;
- la DAF peut demander un complement, rejeter avec motif ou transmettre un avis favorable a la DG ;
- la DG accorde ou refuse definitivement le financement ;
- toute correction demandee par la DAF impose une nouvelle piece justificative ;
- les transitions sont autorisees cote serveur, executees sous verrou transactionnel et protegees contre les doubles decisions ;
- les fichiers sont chiffres selon la politique documentaire et nettoyes si une transaction echoue ;
- les notifications, journaux d'action, audits, taches personnelles et controles de cloture utilisent les memes statuts ;
- la file financiere fournit une synthese administrative pour le RMO, la DAF, la DG et les lecteurs globaux ;
- l'ancienne commande DAF de mise a jour directe d'un resultat final est retiree du circuit.

## 41. Portefeuille visuel exhaustif des actions

Les modes Kanban, Calendrier et Gantt ne sont plus limites aux actions de la page courante du tableau.

- la liste administrative conserve sa pagination configurable de 15 a 100 lignes ;
- chaque visualisation restitue toutes les actions correspondant au perimetre et aux filtres actifs ;
- la recherche, l'exercice, les statuts, les rattachements PTA et les vues par profil restent coherents entre les quatre modes ;
- le scope serveur interdit toute exposition d'une action appartenant a une autre direction ou a un autre service ;
- les donnees exhaustives ne sont chargees que lorsqu'une visualisation le requiert et avec un ensemble de colonnes limite ;
- les compteurs affiches decrivent le portefeuille filtre et non la page courante ;
- aucune donnee metier ni aucun workflow n'est restructure pour cette evolution de consultation.

## 42. Stabilite des graphiques du tableau de bord

Le tableau de bord doit conserver un rendu graphique stable pendant la navigation entre ses onglets et lors du redimensionnement de la fenetre.

- les graphiques places dans un onglet masque attendent que leur conteneur soit visible avant leur initialisation ;
- le passage vers l'onglet des graphiques declenche leur montage avec des dimensions valides ;
- les annotations de seuil sont limitees aux graphiques qui les utilisent effectivement ;
- un graphique invisible n'est pas redimensionne et une erreur locale ne bloque pas les autres visualisations ;
- les comportements sont identiques en theme clair, en theme sombre et sur les largeurs de consultation prises en charge ;
- cette stabilisation ne change ni les indicateurs, ni les filtres, ni le perimetre de donnees affiche.

## 43. Poste de travail Mes taches

Le module `Mes taches` devient la file administrative et operationnelle commune a tous les profils.

- chaque utilisateur retrouve toutes les executions, corrections, validations, controles, financements, alertes et decisions qui lui sont effectivement attribues ;
- aucune limite technique silencieuse ne masque les elements anciens lorsque le volume depasse une page ;
- les retards et urgences sont prioritaires, avec des vues dediees aux echeances sous 24 heures, aux cas critiques et aux travaux sans echeance ;
- la recherche, les familles metier, le tri et la pagination permettent de traiter un volume important sans perdre le contexte ;
- chaque ligne presente le sujet, le contexte, le responsable, la reception, l'echeance, le temps restant et l'impact sur le score ;
- les chefs peuvent viser ou renvoyer les saisies qui leur sont soumises sans quitter la file ;
- un motif est obligatoire avant tout renvoi pour correction ;
- les controleurs SCIQ et Planification, la DAF et la DG conservent leurs actes sensibles dans les fiches metier correspondantes ;
- les filtres reduisent uniquement le perimetre deja autorise et ne peuvent exposer le travail d'un autre service ou d'une autre direction ;
- la page est utilisable sur ordinateur et mobile, en theme clair comme en theme sombre.

## 44. Centre administratif Notifications et Alertes

Le centre personnel regroupe les messages de travail et les signaux de controle sans confondre leur cycle de lecture.

- la boite de notifications presente une synthese, une recherche, des filtres par etat, niveau et module, puis une pagination configurable ;
- les alertes disposent de deux vues, actives et historique, avec recherche et filtres par niveau, origine et etat ;
- toutes les alertes autorisees sont consultables, y compris lorsque leur nombre depasse cent elements ;
- le marquage global couvre la totalite des alertes actives et archive leur contenu pour la consultation ulterieure ;
- chaque ligne affiche clairement la priorite, l'origine, le perimetre, la date et la commande d'ouverture ;
- les notifications ne marquent jamais les alertes comme lues, et reciproquement ;
- les liens de notifications sont limites aux destinations internes et les alertes respectent les scopes serveur existants ;
- seuls les profils possedant les permissions Planification et Alertes accedent au centre des alertes ;
- les parametres invalides sont neutralises et ne modifient jamais le perimetre autorise ;
- l'interface est dense, responsive et compatible avec les themes clair et sombre.

## 45. Poste administratif Audit et tracabilite

Le journal d'audit devient un poste de consultation et de preuve commun aux profils explicitement habilites.

- l'acces a la page, a l'API et a l'export exige la permission sensible `audit.read` ;
- les vues recentes, interventions, sensibles et organisation permettent d'isoler rapidement les actes a controler ;
- les filtres proposent les modules, actions, auteurs et types d'entites reellement presents dans le journal ;
- la recherche couvre egalement le nom, l'adresse electronique et l'adresse IP de l'auteur ;
- chaque evenement affiche son horodatage, son auteur, son module, son action, son entite et les champs modifies ;
- les valeurs avant et apres sont consultables dans la ligne sans quitter le journal ;
- les mots de passe, jetons, secrets, cles API et donnees d'autorisation sont masques dans toutes les sorties ;
- les dossiers PAS, PAO, PTA, Action et report peuvent etre ouverts depuis leur trace lorsqu'ils existent encore ;
- l'export CSV conserve les filtres actifs, neutralise les formules tableur et traite les gros volumes par lots ;
- la pagination, le tri et les index de lecture permettent une utilisation administrative continue sur un historique volumineux ;
- aucune trace existante n'est reecrite ou supprimee par cette refonte.

## 46. Poste administratif Referentiel et Utilisateurs

Le referentiel organisationnel devient un poste coherent pour la consultation des directions, des services et des comptes.

- les trois ecrans partagent la meme navigation, les memes conventions visuelles, des syntheses, des filtres, des tris et une pagination configurable ;
- les directions et services presentent leur etat, leurs effectifs et les objets de planification rattaches ;
- l'annuaire presente le role, le rattachement, la fonction, les coordonnees utiles et la sante de chaque compte ;
- des vues dediees isolent les comptes inactifs, suspendus, en attente de renouvellement et mal rattaches ;
- les droits serveur et les perimetres direction, service et global sont appliques avant toute recherche, pagination ou export ;
- chaque liste peut etre exportee en CSV sans exposer de secret et sans risque d'execution de formule tableur ;
- aucun mot de passe commun n'est affecte aux nouveaux comptes ou aux reinitialisations ;
- chaque identifiant temporaire est unique, affiche une seule fois et doit etre remplace a la prochaine connexion ;
- les reinitialisations de masse generent un secret distinct par compte et restent limitees a cent utilisateurs ;
- les imports refusent la creation d'un compte sans mot de passe conforme ;
- les modifications, suppressions, controles d'impact et traces d'audit existants restent applicables.
