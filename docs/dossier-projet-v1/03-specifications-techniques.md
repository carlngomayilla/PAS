# Specifications Techniques
## Application e-Pilotage ANBG - PAS / PAO / PTA / Actions

| Element | Reference |
| --- | --- |
| Version documentaire | 2.0 |
| Date de mise a jour | 31 juillet 2026 |
| Application | e-Pilotage ANBG |
| Statut | Reference technique de production |

## 1. Objet et perimetre

Cette specification decrit l'architecture effective de l'application e-Pilotage ANBG. Elle couvre la planification strategique (PAS), les plans operationnels (PAO), les plans de travail annuels (PTA), le suivi des actions, les circuits de validation, les justificatifs, le reporting, les imports et rapports assistes par IA, la gouvernance et le suivi financier.

Le document ne remplace pas les regles metier detaillees du cahier de specifications fonctionnelles. Il explique comment ces regles sont implantees, securisees, exploitees et maintenues.

## 2. Socle logiciel

### 2.1 Backend

- PHP : projet compatible PHP `^8.2`; plateforme Composer verrouillee sur PHP 8.3.31. Le serveur de production utilise un service PHP-FPM parametrable, actuellement `php8.4-fpm`.
- Framework : Laravel 13.
- Authentification API : Laravel Sanctum 4.
- Tests : PHPUnit 12 et Laravel Boost.
- Qualite PHP : Laravel Pint.
- Exports : DomPDF pour le PDF, Laravel Excel pour les fichiers tableur et PHPWord pour Word.
- IA : package `laravel/ai`. Le fournisseur autorise pour les imports et rapports IA est OpenAI, configure par variable d'environnement et jamais remplace silencieusement.
- Analyse documentaire : `smalot/pdfparser` pour les documents PDF autorises par les parcours d'import.

### 2.2 Frontend

- Rendu serveur : Blade Laravel.
- Styles : Tailwind CSS 4, avec CSS applicatif complementaire pour le shell institutionnel et les composants de formulaire.
- Bundler : Vite 7 et `laravel-vite-plugin` 2.
- Visualisation : Chart.js, extensions Matrix, Treemap, Annotation et DataLabels; D3, Plotly et GSAP sont disponibles pour les vues avancees.
- Temps reel : Laravel Echo et Pusher JS sont prepares pour les notifications et evolutions de diffusion en direct.

### 2.3 Donnees et fichiers

- SGBD : base SQL configuree par l'environnement Laravel. Les migrations garantissent le schema, les cles et les index; aucun moteur n'est impose dans le code metier.
- Fichiers : justificatifs et pieces jointes sont stockes via le stockage Laravel securise. Les telechargements passent par des routes autorisees, non par des URL publiques directes.
- File d'attente : la production est prevue avec `QUEUE_CONNECTION=database` et un worker systemd. Le script de deploiement notifie les workers par `php artisan queue:restart`.

## 3. Architecture applicative

L'application applique une architecture Laravel en couches.

| Couche | Responsabilite |
| --- | --- |
| Routes web et API | Exposent les parcours, appliquent les middlewares et utilisent les routes nommees. |
| Controllers Web | Orchestrent les ecrans Blade, les redirections et les messages utilisateur. |
| Form Requests | Verifient les droits de l'acteur et valident les donnees avant toute mutation. |
| Services metier | Centralisent les calculs, les workflows, les limites budgetaires, les notifications et les scopes. |
| Modeles Eloquent | Gerent les relations, casts, factories et persistance transactionnelle. |
| Policies, traits et scopes | Appliquent le RBAC et les perimetres global, direction, service ou affectation. |
| Audit et notifications | Tracent les mutations et distribuent les informations selon le role et le perimetre. |

Les operations sensibles reposent sur des transactions de base de donnees et des verrous lorsque deux utilisateurs pourraient modifier la meme situation. Le suivi financier utilise notamment `lockForUpdate()` lors de l'enregistrement d'une operation afin d'eviter un depassement concurrent.

## 4. Modules et composants principaux

### 4.1 Planification

- PAS : axes strategiques, objectifs strategiques, cycle de vie, cloture et archivage.
- PAO : declinaison annuelle par direction et objectifs operationnels.
- PTA : construction par service, parametrage direct des actions, sous-actions, indicateurs, responsables, cibles, risques et budgets estimes.
- Imports : import structure de fichiers et import assiste par IA dans une entree de navigation unique, avec onglets distincts.

### 4.2 Execution et suivi

- Actions : planification, affectation des responsables de mise en oeuvre, parametres, dates et budget estime.
- Suivi : avancement, quantites, commentaires, difficultes, justificatifs et soumission.
- Validation : visa hierarchique, controle Planification/SCIQ, corrections motivees et performance officielle.
- Reports d'echeance : demande avec piece obligatoire, avis du chef, controle, decision finale DG ou Chef Planification et application de la nouvelle date uniquement par un controleur habilite apres approbation.
- Financement initial : dossier de besoin de financement de l'action, circuit RMO, DAF puis DG lorsque ce parcours est actif.

### 4.3 Pilotage et gouvernance

- Tableaux de bord par profil, alertes, taches, reporting et exports.
- Rapports assistes par OpenAI : l'application calcule les chiffres et transmet un instantane structure; OpenAI propose un brouillon soumis a controle humain et a un controle de conformite de template.
- Referentiels : directions, services, utilisateurs, exercices, roles, permissions et parametres de plateforme.
- Audit : journal en ajout uniquement pour les operations sensibles, avec acteur, contexte, valeurs avant/apres expurgees et metadonnees de requete.
- Suppressions : les suppressions de donnees protegees passent par une demande, une validation du Chef Planification puis une execution par Admin ou Super Admin suivant les droits accordes. Les decisions sont auditees.

## 5. Controle des acces et perimetres

L'authentification est obligatoire pour les espaces de travail. Le profil utilisateur porte un role, des rattachements organisationnels (`direction_id`, `service_id`) et, pour les agents, un matricule soumis a des controles d'integrite.

Les principes suivants s'appliquent cote serveur :

- les administrateurs et les profils globaux voient les donnees autorisees au niveau agence;
- les directions voient leur direction, les services leur service, les agents les actions auxquelles ils sont affectes;
- Planification, SCIQ et leurs responsables disposent des vues de controle prevues;
- la visibilite d'un bouton ne constitue jamais une autorisation : le controller, la request et le service recontrolent les droits;
- les exports, les telechargements et les recherches sont scopes avant lecture des donnees.

## 6. Suivi financier

### 6.1 Objectif

Le budget estime d'une action est sa prevision approuvee avant saisie dans l'application. Le module financier ne revalide pas cette enveloppe initiale : il suit les engagements et les decaissements reellement realises, par action, service et direction.

### 6.2 Donnees

La table `financial_transactions` enregistre les mouvements confirmes :

- `action_id` : action concernee;
- `operation_type` : `engagement` ou `decaissement`;
- `amount` : montant decimal sur 15 chiffres dont 2 decimales;
- `operated_on` : date de l'operation;
- moyen de paiement, reference, beneficiaire et commentaire;
- utilisateur ayant saisi l'operation;
- justificatifs polymorphes rattaches au mouvement.

Une piece est obligatoire pour un decaissement. Les formats et tailles acceptes sont controles par la politique documentaire. Le stockage est securise et toute erreur de sauvegarde declenche le nettoyage du fichier deja depose.

### 6.3 Autorisations

- Saisie : exclusivement les profils Direction ou Service rattaches a la DAF. Cette regle couvre la directrice DAF et les chefs de service DAF habilites.
- Lecture globale : administration, DG, Planification, Chef Planification, SCIQ et responsables SCIQ autorises.
- Lecture de perimetre : directeurs et chefs de service consultent les budgets, paiements et restes de leur direction ou service, sans pouvoir saisir une operation si leur rattachement n'est pas DAF.

### 6.4 Calculs

Pour chaque action, le service `FinancialMonitoringService` calcule :

- budget effectif = budget estime + depassements approuves de l'action;
- engagements = somme des mouvements `engagement`;
- decaissements = somme des mouvements `decaissement`;
- reste disponible = budget effectif - decaissements;
- taux d'engagement = engagements / budget effectif x 100;
- taux de decaissement = decaissements / budget effectif x 100.

Les budgets service et direction sont la somme des budgets estimes des actions rattachees. Les controles tiennent aussi compte des depassements approuves aux niveaux action, service et direction.

### 6.5 Controle de depassement

Une operation qui depasse le budget effectif est refusee. Une demande de depassement peut viser une action, un service ou une direction. Son circuit est le suivant :

1. un responsable habilite DAF renseigne le montant supplementaire, le motif et, si disponible, la preuve;
2. si le demandeur est un chef de service DAF, la directrice DAF transmet ou rejette avec une note motivee;
3. la DG approuve ou rejette avec une note motivee;
4. seul un depassement approuve augmente le budget effectif et autorise les nouveaux engagements ou decaissements.

Les tables `financial_transactions` et `budget_overrun_requests` portent des index sur les filtres critiques, notamment action/type/date et perimetre/statut.

## 7. Workflows et integrite

Les transitions PAS, PAO, PTA, action, suivi, report, financement et suppression sont validees par les services et les Form Requests. Les retours en correction sont motives. Les ecritures sont gelees lorsque l'etat metier l'impose.

Les contraintes importantes sont :

- une action appartient a un PTA;
- les relations direction/service sont coherentes;
- les dates ne changent pas hors circuit de report;
- une demande de suppression ne peut pas etre executee sans decision requise;
- les montants financiers positifs sont limites par le budget effectif;
- les justificatifs restent rattaches a l'entite et ne sont telecharges qu'apres controle du perimetre.

## 8. Securite

- middleware d'authentification, compte actif et fraicheur du mot de passe;
- limitation de debit de connexion et des points sensibles;
- politique de mot de passe, historique et rotation forcee lorsque configuree;
- protection CSRF des formulaires web et en-tetes de securite;
- validation serveur de tous les champs, y compris type MIME et taille des fichiers;
- analyse antivirus et stockage securise des justificatifs selon la configuration;
- exclusion des mots de passe, tokens, cles et cookies des traces d'audit;
- routes de telechargement autorisees et verifiees cote serveur;
- journalisation des creations, validations, decisions, suppressions et operations financieres.

## 9. Reporting, IA et exports

Les indicateurs sont calcules depuis la base de donnees et limites au perimetre de l'utilisateur. Les exports CSV, Excel, PDF et Word reprennent les filtres autorises et ne doivent pas etre diffuses sans revue metier.

Pour les imports et rapports IA :

- OpenAI est le fournisseur unique autorise;
- l'IA sert a extraire, structurer ou rediger a partir d'un instantane controle;
- une validation humaine reste obligatoire avant tout import definitif ou rapport institutionnel;
- les rapports IA conservent les metadonnees utiles de controle : fournisseur, modele, template, version, date et resultat de conformite;
- une rubrique absente ou deplacee peut bloquer la validation et l'export du rapport.

## 10. Tests et qualite

Les tests feature PHPUnit couvrent les parcours autorises, les refus d'acces, les invalidations et les transitions. Le module financier est couvert par `tests/Feature/FinancialMonitoringWorkflowTest.php` et les parcours de financement d'action par `tests/Feature/ActionFinancingWorkflowTest.php`.

Avant livraison d'une evolution :

1. executer le ou les tests feature concernes avec `php artisan test --compact`;
2. formatter les fichiers PHP modifies avec `vendor/bin/pint --dirty --format agent`;
3. executer `npm run build` lorsqu'un asset frontend est modifie;
4. verifier les migrations et la compatibilite du workflow sur une base de recette si le schema evolue.

## 11. Exploitation et deploiement

### 11.1 Prerequis serveur

- Ubuntu ou serveur Linux compatible systemd;
- PHP, Composer, Node.js et npm;
- acces Git en lecture au depot;
- base SQL et variables de production correctement renseignees;
- service de worker de file d'attente;
- service PHP-FPM configure;
- `APP_DEBUG=false` en production.

### 11.2 Deploiement automatise

Le depot contient `.github/workflows/deploy-production.yml`. Le workflow se lance apres la reussite du workflow `tests` sur la branche `main`, ou manuellement. Il s'execute sur le runner auto-heberge `ap2-pas-production`, actif sur le serveur `ap2` et etiquete `pas-production`.

Le choix d'un runner local est intentionnel : le serveur cible utilise une adresse privee et n'est pas expose aux runners GitHub publics. Le workflow est protege par l'environnement GitHub `production` et par une concurrence unique `production-deployment`.

Le script `scripts/deploy.sh` effectue, dans cet ordre :

1. verification de `APP_DEBUG=false`;
2. passage temporaire en maintenance avec filet de securite de remise en ligne;
3. `git pull --ff-only`;
4. `composer install --no-dev --optimize-autoloader`;
5. `npm ci` et `npm run build`;
6. `php artisan migrate --force`;
7. `php artisan storage:link` si necessaire;
8. nettoyage puis regeneration des caches Laravel;
9. redemarrage des workers;
10. tentative non bloquante de rechargement PHP-FPM;
11. sortie explicite de maintenance.

### 11.3 Verification et retour arriere

Apres chaque deploiement, verifier :

- le statut du run GitHub Actions;
- l'etat du service `actions.runner.carlngomayilla-PAS.ap2-pas-production.service`;
- la version Git de `/var/www/pas-anbg/PAS`;
- `php artisan migrate:status`;
- la connexion, le tableau de bord, un export et un telechargement de justificatif sur l'application.

En cas d'echec, le script remet l'application en ligne grace au trap de maintenance. Le retour arriere doit etre realise par un commit correctif ou un retour Git valide, puis par l'execution controlee du meme script. Une restauration de base de donnees exige une sauvegarde valide et une decision d'exploitation explicite.

## 12. References internes

- `README.md` : installation, environnement et verification post-deploiement.
- `GUIDE.md` : lecture generale de l'application.
- `docs/specifications-fonctionnelles.md` : regles fonctionnelles et criteres d'acceptation.
- `docs/MANUEL_UTILISATION_E_PILOTAGE_PAS.md` : procedures utilisateur.
- `tests/Feature/FinancialMonitoringWorkflowTest.php` : couverture du suivi financier.
- `.github/workflows/deploy-production.yml` et `scripts/deploy.sh` : reference executable du deploiement.
