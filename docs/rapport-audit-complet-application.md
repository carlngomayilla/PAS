# Rapport d'audit complet de l'application PAS ANBG

Date d'audit : 5 mai 2026  
Perimetre : application Laravel / Blade / Tailwind dans son etat courant  
Objectif : relever les incoherences structurelles, logiques, fonctionnelles et UI/UX, module par module.

## 1. Synthese executive

L'application est riche fonctionnellement et couvre deja les grands blocs attendus : PAS, PAO, PTA, actions, suivi agent, reporting, alertes, referentiel, messagerie, gouvernance et super administration. Le probleme principal n'est pas l'absence de fonctionnalites, mais l'accumulation de plusieurs generations de logique et d'interface dans le meme code.

Les incoherences les plus importantes sont les suivantes :

| Priorite | Constat | Impact |
| --- | --- | --- |
| P0 | Des vues referencent des routes qui n'existent plus ou ne sont plus exposees. | Clics cassables, erreurs Laravel `Route [...] not defined`, pages orphelines. |
| P0 | La suite de tests unitaires echoue sur le calcul de progression des actions. | Risque direct sur le suivi agent, les indicateurs et le reporting. |
| P0 | La suite feature echoue sur un libelle attendu dans le formulaire PTA. | Les formulaires PTA ont diverge des attentes metier/test. |
| P1 | La logique de suivi des actions est repartie entre modele, service, controller web, controller API et vues. | Risque eleve de double affichage quantitatif/qualitatif/mixte et de comportements contradictoires. |
| P1 | Plusieurs formulaires sensibles n'ont pas de confirmation visible. | Risque d'action accidentelle : publication, archivage, rejet, reinitialisation, revocation. |
| P1 | Le design system est en transition entre `#1c203d` et `#3996d3`. | Interface incoherente, surcharge CSS, corrections visuelles fragiles. |
| P1 | Plusieurs composants UI coexistent pour les memes usages : sidebar, boutons, cartes, tableaux, champs de recherche. | Apparence differente selon les pages, maintenance difficile. |
| P2 | Beaucoup de textes visibles sont non accentues ou heterogenes. | Finition institutionnelle insuffisante, impression de prototype. |
| P2 | Le layout principal contient beaucoup de logique JS/CSS inline. | Navigation lourde, scripts difficiles a tester, regressions probables. |
| P2 | Des modules anciens restent presents dans les vues alors que leurs controllers/routes web ont ete retires. | Confusion fonctionnelle et dette technique. |

## 2. Methode et relevés

Audit realise par lecture statique des routes, controllers, modeles, services, vues Blade, CSS/JS et par execution de tests cibles.

Releves automatises :

| Mesure | Resultat |
| --- | ---: |
| Routes listees par `php artisan route:list` | 265 |
| Routes nommees | 259 |
| References `route(...)` / `signedRoute(...)` scannees | 1 526 |
| References de routes non resolues detectees | 74 |
| Vues Blade principales inspectees | plus de 100 |

Pages Blade les plus chargees en formulaires, boutons et routes :

| Vue | Formulaires | Boutons/liens d'action | References route | Lignes |
| --- | ---: | ---: | ---: | ---: |
| `resources/views/workspace/super_admin/organization.blade.php` | 13 | 41 | 31 | 661 |
| `resources/views/workspace/monitoring/pilotage.blade.php` | 1 | 11 | 53 | 704 |
| `resources/views/workspace/actions/suivi.blade.php` | 10 | 22 | 14 | 916 |
| `resources/views/workspace/messaging/index.blade.php` | 5 | 26 | 14 | 430 |
| `resources/views/workspace/pas/index.blade.php` | 6 | 15 | 16 | 199 |
| `resources/views/workspace/pao/index.blade.php` | 6 | 15 | 15 | 231 |
| `resources/views/workspace/pta/index.blade.php` | 6 | 15 | 15 | 214 |
| `resources/views/workspace/actions/index.blade.php` | 2 | 10 | 19 | 359 |
| `resources/views/layouts/admin.blade.php` | 2 | 17 | 11 | 1 358 |

Limites de l'audit :

- L'audit est principalement statique, complete par des tests automatises.
- Aucun scenario navigateur authentifie complet n'a ete joue page par page avec des comptes reels.
- Les constats portent sur l'etat courant du depot, qui contient deja de nombreuses modifications non commitees.

## 3. Resultats des tests

Commandes executees :

```bash
php artisan test --testsuite=Unit --stop-on-failure
php artisan test tests/Feature/WebWorkspaceTest.php --stop-on-failure
```

Resultats :

| Commande | Resultat |
| --- | --- |
| `php artisan test --testsuite=Unit --stop-on-failure` | Echec |
| `php artisan test tests/Feature/WebWorkspaceTest.php --stop-on-failure` | Echec |

Echec unitaire :

- Test : `Tests\Unit\ActionTrackingServiceTest > refresh metrics computes completed action kpis and status`
- Attendu : `progression_reelle = 100.00`
- Obtenu : `progression_reelle = 0.00`
- Fichier test : `tests/Unit/ActionTrackingServiceTest.php:53`

Interpretation :

Le calcul de progression actuel semble privilegier les nouvelles donnees structurees de quantite / sous-actions. Une action ancienne ou incomplete peut etre ramenee a 0 meme lorsqu'un historique de suivi ou un statut d'achevement existe. C'est un point critique pour les actions agents et le reporting.

Echec feature :

- Test : `crud forms hide legacy fields from old model`
- Attendu dans le formulaire PTA : `Objectif operationnel transmis au service`
- Resultat : texte absent du rendu
- Fichier test : `tests/Feature/WebWorkspaceTest.php:497`

Interpretation :

Le formulaire PTA a diverge du contrat attendu. Cela peut venir d'un changement de libelle, d'un champ supprime, d'une condition d'affichage ou d'un formulaire remplace sans mise a jour des tests.

## 4. Incoherences structurelles

### 4.1 Routes et vues orphelines

Plusieurs vues continuent de pointer vers des routes qui ne sont pas presentes dans la table de routes actuelle.

Routes manquantes visibles dans les vues ou controllers :

| Route referencee | Fichiers concernes | Risque |
| --- | --- | --- |
| `password.request` | `resources/views/auth/lamp-login.blade.php` | Lien mot de passe oublie cassable. |
| `register` | `resources/views/welcome.blade.php` | Lien inscription cassable. |
| `workspace.justificatifs.*` | `resources/views/workspace/justificatifs/*`, `JustificatifWebController` | Module justificatifs web partiellement orphelin. |
| `workspace.kpi.store/update/destroy` | `resources/views/workspace/kpi/*` | CRUD KPI web incoherent. |
| `workspace.kpi-mesures.store/update/destroy` | `resources/views/workspace/kpi_mesures/*` | CRUD mesures KPI incoherent. |
| `workspace.pao-axes.*` | `resources/views/workspace/pao_axes/*` | Ancien sous-module PAO encore present. |
| `workspace.pao-objectifs-operationnels.*` | `resources/views/workspace/pao_objectifs_operationnels/*` | Ancien sous-module PAO encore present. |
| `workspace.pao-objectifs-strategiques.*` | `resources/views/workspace/pao_objectifs_strategiques/*` | Ancien sous-module PAO encore present. |
| `justificatifs.download` | `app/Http/Controllers/Api/JustificatifController.php` | Lien de telechargement API/web non aligne. |

Certaines references signalees dans des FormRequest (`$this->route('action')`, `$this->route('pta')`, etc.) sont des faux positifs de scan car elles recuperent un parametre de route, pas une route nommee. Elles ne sont pas un probleme.

### 4.2 Noms de routes dupliques

Le scan de la table de routes remonte un nom de route duplique : `workspace.`.

Cause probable :

- Redirections legacy pour `pas-axes/{legacy?}` et `pas-objectifs/{legacy?}` placees dans un groupe nomme sans nom explicite.

Impact :

- Ce n'est pas forcement visible pour l'utilisateur, mais c'est un signal de structure route fragile.
- Les outils Laravel, tests et debugs deviennent moins lisibles.

### 4.3 Controllers supprimes mais vues conservees

Des controllers web de sous-CRUD semblent supprimes alors que les vues correspondantes restent dans `resources/views` :

- `PaoAxeWebController`
- `PaoObjectifStrategiqueWebController`
- `PaoObjectifOperationnelWebController`
- `PasAxeWebController`
- `PasObjectifWebController`

Les vues `pao_axes`, `pao_objectifs_*`, `kpi`, `kpi_mesures`, `justificatifs` restent presentes et appellent encore des routes CRUD. Il faut choisir une strategie unique :

1. soit restaurer les routes/controllers si ces pages doivent rester accessibles ;
2. soit supprimer/archiver les vues orphelines ;
3. soit remplacer ces pages par des redirections propres vers les nouveaux formulaires integres.

## 5. Incoherences UI/UX globales

### 5.1 Design system non stabilise

Deux couleurs principales coexistent :

- couleur demandee : `#3996d3`
- ancienne couleur dominante : `#1c203d`

Exemples :

- `resources/css/app.css` declare `--color-primary: #1c203d`, puis surcharge plus loin avec `#3996d3`.
- `resources/views/auth/lamp-login.blade.php` utilise encore un fond `#1c203d`.
- `resources/views/partials/app-icons.blade.php` utilise `theme-color="#1c203d"`.
- `resources/js/chart-theme.js` et `resources/js/dashboard-render.js` utilisent encore `#1c203d` comme couleur d'emphase.

Impact :

- L'application ne peut pas avoir une identite visuelle stable tant que les variables racines, les classes Tailwind inline et les scripts de graphiques ne partagent pas la meme palette.
- Les correctifs CSS a base de `!important` s'accumulent et rendent les regressions probables.

Recommandation :

- Definir `#3996d3` comme couleur primaire dans une seule source : Tailwind config + variables CSS racines.
- Garder `#1c203d` uniquement comme couleur secondaire sombre, texte ou accent institutionnel, pas comme couleur principale.

### 5.2 Trop de composants pour les memes usages

Composants ou blocs qui se recoupent :

- Sidebar :
  - `resources/views/components/admin/sidebar.blade.php`
  - `resources/views/components/curved-sidebar.blade.php`
  - `resources/views/components/layout/sidebar.blade.php`
- Stat cards :
  - `resources/views/components/stat-card.blade.php`
  - `resources/views/components/stat-card-link.blade.php`
  - `resources/views/components/ui/stat-card.blade.php`
- Boutons :
  - classes Tailwind inline ;
  - `btn-primary`, `btn-secondary`, `btn-blue`, `btn-green`, `btn-danger` ;
  - composants `components/ui/button.blade.php`.
- Tableaux :
  - `components/ui/table-card.blade.php`
  - `components/ui/data-table.blade.php`
  - tableaux Blade faits page par page.

Impact :

- Une meme action peut avoir une apparence differente selon le module.
- Les corrections de padding, icones, focus, dark mode ou responsive doivent etre repetees.

### 5.3 Layout principal trop charge

`resources/views/layouts/admin.blade.php` contient environ 1 358 lignes et melange :

- structure HTML globale ;
- navbar ;
- dropdown notifications ;
- dropdown messages ;
- dark mode ;
- recherche ;
- dialogs JS ;
- styles et scripts inline.

Impact :

- Les bugs visuels globaux deviennent difficiles a localiser.
- Les interactions header/sidebar ne sont pas facilement testables separement.
- Les performances et le poids initial peuvent etre affectes.

Recommandation :

- Extraire navbar, notifications, messages, profil, recherche globale et scripts dans des composants/modules JS dedies.
- Laisser le layout orchestrer, pas tout contenir.

### 5.4 Recherche et icone loupe

Un fichier JS `resources/js/ui-enhancements.js` contient une logique de correction globale des liens `a[href="#"]` et des champs de recherche. Cette approche masque certains symptomes, mais ne remplace pas une correction source.

Risque :

- Si une page rend une icone loupe brute, une pseudo-icone CSS et un composant Input avec icone, les doublons restent possibles.
- Le JS global peut corriger visuellement une page mais pas une autre selon l'ordre de chargement.

Recommandation :

- Creer un composant Blade unique pour les champs de recherche.
- Definir une taille d'icone stricte : `w-4 h-4` ou `w-5 h-5`.
- Interdire les loupes ajoutees par `background-image` si un SVG est deja rendu.

### 5.5 Boutons et classes

Occurrences scannees :

| Classe | Occurrences approx. |
| --- | ---: |
| `btn-secondary` | 137 |
| `btn-primary` | 111 |
| `btn-blue` | 44 |
| `btn-amber` | 18 |
| `btn-red` | 16 |
| `btn-green` | 16 |
| `btn-outline` | 5 |
| `btn-danger` | 3 |
| `btn-delete` | 1 |

Constat :

- `btn-blue`, `btn-green`, `btn-danger` sont definies dans le CSS.
- `btn-red`, `btn-amber`, `btn-delete` semblent utilisees comme anciennes conventions mais ne sont pas clairement definies comme variantes coherentes.
- Plusieurs boutons sensibles sont de simples boutons ou formulaires sans confirmation.

Recommandation :

- Centraliser les variantes : primary, secondary, danger, success, warning, ghost, icon.
- Remplacer les classes historiques par le composant bouton.
- Ajouter une convention obligatoire pour les actions destructrices : confirmation, methode HTTP correcte, label explicite.

## 6. Audit fonctionnel par module

## 6.1 Authentification et page d'accueil

Fichiers principaux :

- `resources/views/auth/login.blade.php`
- `resources/views/auth/lamp-login.blade.php`
- `resources/views/layouts/guest.blade.php`
- `resources/views/welcome.blade.php`

Constats :

- La page `lamp-login` reference `route('password.request')`, mais la route n'existe pas dans la table actuelle.
- `welcome.blade.php` reference `route('register')`, mais la route n'existe pas.
- La palette invite encore l'ancien bleu `#1c203d`.
- L'experience invite / auth a son propre CSS (`resources/css/guest.css`) avec une identite visuelle differente du back-office.

Formulaires :

- Login : a verifier en navigateur pour messages d'erreur, focus, accessibilite et dark mode.
- Mot de passe oublie : lien potentiellement cassable.
- Inscription : lien potentiellement cassable si l'inscription n'est pas activee.

Boutons :

- Bouton de connexion : fonctionnel si route login OK.
- Liens secondaires : doivent etre conditionnes a l'existence des routes.

Priorite :

- P0 : corriger ou masquer les liens `password.request` et `register`.

## 6.2 Layout, sidebar, navbar

Fichiers principaux :

- `resources/views/layouts/admin.blade.php`
- `resources/views/layouts/workspace.blade.php`
- `resources/views/components/admin/sidebar.blade.php`
- `resources/views/components/curved-sidebar.blade.php`
- `resources/views/components/layout/sidebar.blade.php`
- `resources/css/app.css`
- `resources/js/ui-enhancements.js`

Constats structurels :

- Plusieurs implementations de sidebar coexistent.
- Le layout admin contient beaucoup de logique de notification, messagerie, recherche, dark mode et dialogs.
- Le CSS global contient de nombreuses surcharges `html:not(.dark) body.admin-theme-scope ...` et `!important`.

Constats design :

- La sidebar est globalement orientee vers `#3996d3`, mais plusieurs zones gardent `#1c203d`.
- Le logo a ete traite avec fond blanc dans certaines zones, mais il faut verifier l'application uniforme sur toutes les sidebars disponibles.
- Les largeurs/retraits de sidebar peuvent diverger selon le composant effectivement utilise.
- La navbar est dense : notifications, messagerie, theme, profil, recherche et dropdowns dans un seul bloc.

Boutons/interactions :

- Toggle sidebar : a tester en mobile/tablette/desktop.
- Recherche sidebar : doit etre absente en mode retracte.
- Bouton dark/light : present, mais il faut verifier que les classes dark ne forcent pas des fonds sombres en mode clair.
- Dropdown notifications/messages : interactions JS sensibles au layout.

Priorite :

- P1 : choisir une seule sidebar officielle.
- P1 : extraire la navbar en composants.
- P1 : nettoyer la palette globale et les surcharges CSS.

## 6.3 Dashboard / espace de travail

Fichiers principaux :

- `resources/views/dashboard.blade.php`
- `resources/views/admin/dashboard.blade.php`
- `resources/views/workspace/index.blade.php`
- `resources/views/partials/dashboard-analytics.blade.php`
- `resources/views/partials/dashboard-reporting-analytics.blade.php`
- `resources/views/partials/dashboard-role-overview.blade.php`
- `resources/js/dashboard-render.js`
- `resources/js/dashboard-charts-init.js`

Constats :

- Les partials de dashboard sont tres riches et contiennent beaucoup de logique de presentation.
- Les graphiques utilisent encore deux palettes (`#3996d3` et `#1c203d`).
- Certains tableaux et cartes sont tres denses, avec styles inline et calculs directement dans Blade.
- Les donnees de dashboard, role overview, reporting analytics et charts semblent fortement couplees au rendu.

Formulaires/filtres :

- Filtres temporels, statutaires et role : a uniformiser avec les autres modules.
- Les champs de recherche et filtres doivent utiliser le meme composant que PAS/PAO/PTA/actions.

Boutons :

- Liens vers PAS/PAO/PTA/actions : semblent nombreux et utiles, mais doivent etre testes avec les scopes utilisateur.
- Export/reporting : verifier droits par role.

Priorite :

- P1 : stabiliser les composants de cartes statistiques.
- P2 : sortir les couleurs de charts vers une configuration unique.
- P2 : reduire les styles inline dans les partials.

## 6.4 Module PAS

Fichiers principaux :

- `resources/views/workspace/pas/index.blade.php`
- `resources/views/workspace/pas/form.blade.php`
- `app/Http/Controllers/Web/PasWebController.php`
- `app/Models/Pas.php`

Constats :

- Le module PAS principal est encore actif.
- Les anciens sous-modules `pas_axes` et `pas_objectifs` ont des traces legacy dans routes/controllers, mais leurs controllers web semblent retires.
- L'index PAS contient plusieurs formulaires d'action sur la meme page.
- Les textes visibles doivent etre harmonises : accents, libelles institutionnels, coherence `PAS actif`, `PAS valide`, `verrouille`.

Formulaire PAS :

- Contient des champs metier essentiels.
- Les anciens champs d'axes/objectifs doivent rester caches si la nouvelle logique les integre ailleurs.
- Les validations entre controller, FormRequest et vue doivent etre comparees.

Boutons :

- Ajouter / modifier / voir / approuver / verrouiller / rouvrir / supprimer selon role.
- Les actions sensibles comme approuver, verrouiller ou rouvrir doivent avoir une confirmation explicite.
- Les actions qui changent le statut doivent afficher clairement la cible du changement.

Logique metier :

- Cycle de statut PAS a clarifier : brouillon, soumis, valide, verrouille, archive.
- Les redirections legacy doivent etre nommees proprement.
- Les droits de scope direction/service doivent etre verifies pour chaque action.

Priorite :

- P1 : nettoyer routes legacy et vues obsoletes.
- P1 : confirmer les actions de changement d'etat.

## 6.5 Module PAO

Fichiers principaux :

- `resources/views/workspace/pao/index.blade.php`
- `resources/views/workspace/pao/form.blade.php`
- `resources/views/workspace/pao_axes/*`
- `resources/views/workspace/pao_objectifs_strategiques/*`
- `resources/views/workspace/pao_objectifs_operationnels/*`
- `app/Http/Controllers/Web/PaoWebController.php`
- `app/Models/Pao.php`

Constats :

- Le formulaire PAO integre probablement des donnees qui etaient auparavant gerees dans des sous-CRUD.
- Les vues des sous-CRUD PAO existent encore mais leurs routes web manquent.
- Les controllers correspondants sont supprimes dans l'etat courant.
- Les routes API exposent encore des ressources legacy, ce qui cree une difference web/API.

Formulaire PAO :

- Long et sensible aux relations PAS -> PAO -> objectifs.
- Risque de champs dynamiques qui ne correspondent plus aux tests ou au modele.
- Les selecteurs d'axes/objectifs doivent etre verifies pour les donnees anciennes.

Boutons :

- Index PAO : plusieurs formulaires d'action comme PAS/PTA.
- Actions de validation/verrouillage : confirmation necessaire.
- Boutons vers anciens axes/objectifs : doivent etre absents si les routes n'existent plus.

Logique metier :

- Clarifier si les axes et objectifs strategiques/operationnels sont :
  - des entites autonomes ;
  - des donnees embarquees dans le PAO ;
  - des objets API seulement.

Priorite :

- P0 : supprimer ou reconnecter les vues `pao_axes` et `pao_objectifs_*`.
- P1 : aligner route web, route API et tests.

## 6.6 Module PTA

Fichiers principaux :

- `resources/views/workspace/pta/index.blade.php`
- `resources/views/workspace/pta/form.blade.php`
- `resources/views/workspace/pta/partials/*`
- `app/Http/Controllers/Web/PtaWebController.php`
- `app/Models/Pta.php`

Constats :

- Le formulaire PTA est un des plus critiques car il relie objectifs operationnels, actions, responsables, ressources et financement.
- Le test feature echoue car le libelle `Objectif operationnel transmis au service` n'est plus present.
- Il y a probablement une divergence entre ancienne logique de champs et nouvelle logique de sous-actions/actions.

Formulaire PTA :

- Plusieurs champs semblent conditionnels.
- Les champs lies aux objectifs operationnels doivent etre stabilises.
- Les champs d'action, responsable, mode de suivi et financement doivent etre coherents avec le module Actions.
- Les libelles doivent etre corriges pour le francais professionnel et pour les tests.

Boutons :

- Ajouter / modifier PTA.
- Ajouter action ou sous-action depuis le formulaire.
- Valider / approuver / verrouiller / rouvrir en index.
- Supprimer : verifier methode DELETE et confirmation.

Logique metier :

- Le PTA cree ou porte les actions agents ; c'est donc un point de naissance du type de suivi.
- La priorite de determination du suivi doit etre claire :
  1. type de suivi defini sur l'action ;
  2. sinon type defini sur la sous-action ;
  3. sinon valeur par defaut.
- Les anciennes actions doivent etre normalisees pour eviter plusieurs types renseignes.

Priorite :

- P0 : corriger la divergence test/formulaire.
- P1 : verrouiller la relation PTA -> objectifs operationnels -> actions -> sous-actions.

## 6.7 Module Actions

Fichiers principaux :

- `resources/views/workspace/actions/index.blade.php`
- `resources/views/workspace/actions/form.blade.php`
- `resources/views/workspace/actions/suivi.blade.php`
- `resources/views/workspace/actions/financements-daf.blade.php`
- `app/Http/Controllers/Web/ActionWebController.php`
- `app/Http/Controllers/Web/ActionTrackingWebController.php`
- `app/Services/Actions/ActionProgressService.php`
- `app/Services/Actions/ActionTrackingService.php`
- `app/Models/Action.php`
- `app/Models/ActionWeek.php`
- `app/Models/SousAction.php`

Constats structurels :

- Le controller `ActionTrackingWebController` est tres volumineux et melange autorisations, validation, workflow, pieces justificatives, notifications, audit et rendu.
- La creation d'action web semble desactivee via routes/redirections, mais le formulaire d'action et les methodes controller existent encore.
- Le suivi est le point le plus risque de l'application.

Constats fonctionnels :

- Le test unitaire montre une regression de progression : action attendue a 100 %, obtenue a 0 %.
- Le calcul actuel depend fortement du mode resolu et des champs quantitatifs/sous-actions.
- Une action ancienne sans `quantite_realisee` peut etre mal interpretee.

Type de suivi :

Champs ou notions a harmoniser :

- `type_action`
- `type_suivi`
- `style_suivi`
- `nature_action`
- `mode_suivi`
- `mode_evaluation`
- `type_cible`
- sous-action quantitative/qualitative/mixte

Regle cible :

- Une action doit avoir un seul type de suivi actif.
- Si le type est quantitatif, afficher uniquement cible, realise, pourcentage, unite et progression.
- Si le type est qualitatif, afficher uniquement description, resultat, commentaire, justificatif et achevement.
- Si le type est mixte, afficher une structure mixte ordonnee, sans double rendu contradictoire.

Formulaire action :

- Les champs de suivi doivent dependre d'une source unique.
- Le formulaire ne doit pas rendre simultanement les anciens champs et les nouveaux champs de sous-action.
- Les validations doivent imposer les champs requis selon le type de suivi.

Page suivi agent :

- Page tres longue avec 10 formulaires et 22 boutons/liens d'action detectes.
- Risque de doublons de blocs quantitatif/qualitatif.
- Risque de permissions dupliquees entre agents, chefs, directions, DAF, DG.
- Plusieurs messages visibles sont non accentues.

Financement DAF :

- Page dediee avec plusieurs formulaires.
- Le rejet de financement devrait avoir confirmation ou modal claire.
- Les statuts financement doivent etre centralises avec les statuts action.

Boutons :

- Voir / modifier / supprimer.
- Suivre / mettre a jour progression.
- Valider chef / rejeter chef.
- Valider direction / rejeter direction.
- Demander financement / approuver DAF / rejeter DAF.
- Cloturer / rouvrir / archiver selon workflow.

Risques :

- Boutons visibles sans droit reel ou droits appliques differemment selon page.
- Actions POST sensibles sans confirmation.
- Double logique web/API pour les memes transitions.

Priorite :

- P0 : corriger le calcul de progression.
- P0 : normaliser le type de suivi actif.
- P1 : extraire une couche metier unique pour les transitions et permissions.
- P1 : reduire `ActionTrackingWebController`.

## 6.8 Module Justificatifs

Fichiers principaux :

- `resources/views/workspace/justificatifs/index.blade.php`
- `resources/views/workspace/justificatifs/create.blade.php`
- `resources/views/workspace/justificatifs/edit.blade.php`
- `app/Http/Controllers/Web/JustificatifWebController.php`
- `app/Http/Controllers/Api/JustificatifController.php`
- `app/Models/Justificatif.php`

Constats :

- Les vues appellent `workspace.justificatifs.*`, mais ces routes ne sont pas presentes dans la table actuelle.
- Le controller API reference `justificatifs.download`, egalement manquant.
- Le module est probablement devenu rattache au suivi action, mais l'ancien CRUD reste dans les vues.

Formulaires :

- Creation justificatif.
- Edition justificatif.
- Suppression justificatif.
- Telechargement.

Boutons :

- Ajouter / modifier / supprimer / telecharger.
- Tous les boutons sont cassables si les routes restent absentes.

Priorite :

- P0 : restaurer les routes ou supprimer les vues du menu.
- P1 : clarifier si le justificatif est autonome ou uniquement rattache aux actions/sous-actions.

## 6.9 Modules KPI et KPI mesures

Fichiers principaux :

- `resources/views/workspace/kpi/index.blade.php`
- `resources/views/workspace/kpi/form.blade.php`
- `resources/views/workspace/kpi_mesures/index.blade.php`
- `resources/views/workspace/kpi_mesures/form.blade.php`
- `app/Http/Controllers/Web/KpiWebController.php`
- `app/Http/Controllers/Web/KpiMesureWebController.php`
- `app/Models/Kpi.php`
- `app/Models/KpiMesure.php`

Constats :

- Les vues KPI et mesures appellent des routes store/update/destroy absentes.
- Le systeme semble avoir migre vers des KPI geres par actions, reporting ou super admin.
- Les vues CRUD anciennes peuvent provoquer des erreurs si elles sont accessibles.

Formulaires :

- KPI : libelle, unite, cible, statut probablement.
- Mesure KPI : valeur, periode, rattachement KPI.

Boutons :

- Ajouter / modifier / supprimer.
- Routes non resolues pour les actions de mutation.

Priorite :

- P0 : reconnecter ou retirer ces ecrans.
- P1 : definir la source officielle des KPI : action, super admin, reporting ou CRUD dedie.

## 6.10 Monitoring, pilotage, reporting, alertes

Fichiers principaux :

- `resources/views/workspace/monitoring/pilotage.blade.php`
- `resources/views/workspace/monitoring/reporting.blade.php`
- `resources/views/workspace/monitoring/alertes.blade.php`
- `resources/views/workspace/monitoring/reporting-pdf.blade.php`
- `resources/views/workspace/monitoring/reporting-word.blade.php`
- `app/Http/Controllers/Web/MonitoringWebController.php`
- `app/Services/Analytics/*`
- `app/Services/Exports/*`

Constats :

- La page pilotage est tres chargee : 704 lignes et 53 references route.
- Les rapports PDF/Word/Excel/CSV semblent riches mais multiplient les templates.
- Les filtres et tableaux ne sont pas forcement harmonises avec PAS/PAO/PTA/actions.
- Les alertes ont des interactions de lecture, export ou consultation a verifier par role.

Formulaires/filtres :

- Filtres par periode, direction, service, statut, priorite.
- Export reporting.
- Marquer alerte comme lue / detail.

Boutons :

- Exporter.
- Filtrer.
- Reinitialiser.
- Ouvrir detail alerte.
- Marquer comme lu.

Risques :

- Performance sur grosses donnees.
- Export incoherent avec filtres affiches.
- Libelles et badges de statut differents de ceux des pages sources.

Priorite :

- P1 : centraliser les libelles statuts/progressions.
- P2 : harmoniser tableaux/filtres avec composants UI.

## 6.11 Referentiel : directions, services, utilisateurs

Fichiers principaux :

- `resources/views/workspace/referentiel/directions/index.blade.php`
- `resources/views/workspace/referentiel/directions/form.blade.php`
- `resources/views/workspace/referentiel/services/index.blade.php`
- `resources/views/workspace/referentiel/services/form.blade.php`
- `resources/views/workspace/referentiel/utilisateurs/index.blade.php`
- `resources/views/workspace/referentiel/utilisateurs/form.blade.php`
- `app/Http/Controllers/Web/ReferentielWebController.php`

Constats :

- Les index directions/services/utilisateurs contiennent plusieurs boutons et routes, mais la structure est plus lisible que super admin.
- Risque de doublon avec les pages organisation de super admin.
- Les droits d'acces doivent etre stricts car ces pages touchent les donnees de structure.

Formulaires :

- Direction : libelle, code, statut, rattachements.
- Service : libelle, direction, statut.
- Utilisateur : identite, role, direction/service, activation.

Boutons :

- Ajouter / modifier / supprimer.
- Activer/desactiver utilisateur.
- Reinitialiser mot de passe selon page.

Risques :

- Deux endroits peuvent modifier les memes donnees : referentiel et super admin organisation.
- Les libelles et confirmations doivent etre uniformises.

Priorite :

- P1 : clarifier la repartition entre referentiel metier et super administration.

## 6.12 Messagerie

Fichiers principaux :

- `resources/views/workspace/messaging/index.blade.php`
- `resources/views/workspace/messaging/partials/profile-card.blade.php`
- `app/Http/Controllers/Web/MessagingWebController.php`
- `app/Services/Messaging/MessagingDirectoryService.php`
- `resources/js/messaging-init.js`

Constats :

- Page riche : 5 formulaires, 26 boutons/liens d'action, 430 lignes.
- La messagerie a des interactions plus proches d'une application temps reel que de pages CRUD classiques.
- Les notifications header et la messagerie sont couplees via le layout admin.

Formulaires :

- Envoi message.
- Recherche conversation.
- Piece jointe.
- Peut-etre creation conversation / reponse rapide.

Boutons :

- Envoyer.
- Joindre fichier.
- Ouvrir conversation.
- Marquer lu.
- Actualiser.

Risques :

- Scripts JS difficiles a isoler si le layout admin gere aussi des notifications globales.
- Accessibilite clavier/focus a verifier.
- Gestion des erreurs upload/message a verifier.

Priorite :

- P2 : isoler davantage le JS messagerie.
- P2 : harmoniser boutons/champs avec le design system.

## 6.13 Gouvernance, audit, retention, delegations

Fichiers principaux :

- `resources/views/workspace/governance/api-docs.blade.php`
- `resources/views/workspace/governance/retention.blade.php`
- `resources/views/workspace/governance/delegations/index.blade.php`
- `resources/views/workspace/audit/index.blade.php`
- `app/Http/Controllers/Web/GovernanceWebController.php`

Constats :

- Module sensible pour audit, retention, delegations et documentation API.
- La page retention contient des actions potentiellement lourdes.
- Certaines confirmations existent, mais pas de maniere uniforme sur toutes les actions sensibles.

Formulaires :

- Retention dry-run.
- Execution retention.
- Delegation creation/annulation.
- Filtres audit.

Boutons :

- Simuler.
- Executer.
- Annuler delegation.
- Exporter audit.

Risques :

- Actions de retention/revocation doivent etre accompagnees d'une confirmation explicite et d'un journal clair.
- Les erreurs doivent etre visibles et non silencieuses.

Priorite :

- P1 : imposer une convention de confirmation sur actions irreversibles.
- P2 : uniformiser les filtres audit/retention.

## 6.14 Profil utilisateur

Fichier principal :

- `resources/views/workspace/profile/edit.blade.php`

Constats :

- Page profil avec plusieurs formulaires.
- Les actions de revocation de sessions sont sensibles.

Formulaires :

- Informations personnelles.
- Mot de passe.
- Sessions.

Boutons :

- Enregistrer.
- Modifier mot de passe.
- Revoquer session.
- Revoquer toutes les sessions.

Risques :

- Les revocations devraient avoir confirmation.
- Les messages succes/erreur doivent etre homogenes.

Priorite :

- P1 : ajouter confirmation aux revocations de sessions.

## 6.15 Super administration

Fichiers principaux :

- `resources/views/workspace/super_admin/index.blade.php`
- `resources/views/workspace/super_admin/settings.blade.php`
- `resources/views/workspace/super_admin/appearance.blade.php`
- `resources/views/workspace/super_admin/modules.blade.php`
- `resources/views/workspace/super_admin/organization.blade.php`
- `resources/views/workspace/super_admin/roles.blade.php`
- `resources/views/workspace/super_admin/workflow.blade.php`
- `resources/views/workspace/super_admin/templates/*`
- `resources/views/workspace/super_admin/snapshots.blade.php`
- `resources/views/workspace/super_admin/maintenance.blade.php`
- `app/Http/Controllers/Web/SuperAdminWebController.php`
- `app/Services/*Settings.php`

Constats :

- C'est le module le plus dense et le plus sensible.
- `organization.blade.php` est la vue la plus chargee detectee : 13 formulaires, 41 boutons, 31 routes.
- Le controller et les services de configuration couvrent beaucoup de responsabilites : apparence, modules, organisation, roles, workflows, templates, notifications, KPI, referentiels, maintenance.
- Les actions sensibles ne sont pas toutes confirmees.

Formulaires principaux :

- Parametres generaux.
- Apparence / theme.
- Modules.
- Organisation : directions, services, utilisateurs.
- Roles et permissions.
- Workflow.
- Templates.
- Snapshots.
- Maintenance.
- Notifications.
- KPI.

Boutons sensibles observes :

- Publier un brouillon.
- Abandonner un brouillon.
- Activer/desactiver direction.
- Activer/desactiver service.
- Activer/desactiver utilisateur.
- Reinitialiser mot de passe.
- Revoquer sessions.
- Modifier affectations.
- Publier/archiver/restaurer template.
- Restaurer snapshot.
- Executer maintenance.

Risques :

- Un clic accidentel peut changer une configuration globale.
- Les boutons d'administration ont besoin d'une convention visuelle plus forte : danger, warning, success, disabled, loading.
- Les messages doivent etre institutionnels, courts et accentues.
- Les parametres de workflow peuvent casser les modules metier si les validations ne sont pas strictes.

Priorite :

- P1 : confirmation obligatoire sur toutes les actions sensibles.
- P1 : separation plus nette des responsabilites du controller.
- P1 : tests feature par sous-page super admin.

## 7. Boutons et interactions sensibles

Formulaires detectes comme sensibles ou potentiellement sensibles sans confirmation uniforme :

- Publication/abandon de brouillon dans `components/super-admin/draft-banner.blade.php`.
- Rejet financement dans `workspace/actions/financements-daf.blade.php`.
- Validation/rejet chef, direction et DAF dans `workspace/actions/suivi.blade.php`.
- Approbations PAS/PAO/PTA dans `workspace/pas/index.blade.php`, `workspace/pao/index.blade.php`, `workspace/pta/index.blade.php`.
- Revocation de sessions dans `workspace/profile/edit.blade.php`.
- Actions organisationnelles dans `workspace/super_admin/organization.blade.php`.
- Publication/archivage/restauration/affectation dans `workspace/super_admin/templates/show.blade.php`.

Regle recommandee :

- Toute action `DELETE`, `archive`, `restore`, `publish`, `reject`, `reset`, `revoke`, `execute`, `lock`, `unlock` doit passer par :
  - un `button type="submit"` dans un formulaire correct ;
  - `@csrf` ;
  - `@method(...)` si necessaire ;
  - une confirmation explicite ;
  - un etat loading/desactive si l'action est longue ;
  - un message de retour clair.

## 8. Logique metier transversale

### 8.1 Statuts et workflows

Constat :

- Les statuts PAS/PAO/PTA/actions/financement/progression sont rendus et interpretes a plusieurs endroits.
- Certains labels sont dans les vues, d'autres dans les services, d'autres dans les modeles ou controllers.

Impact :

- Un meme statut peut avoir plusieurs libelles, couleurs ou conditions d'acces.
- Les exports peuvent diverger de l'interface.

Recommandation :

- Centraliser les labels, couleurs, transitions et permissions de statut dans des enums ou services dedies.
- Utiliser la meme source pour vues, exports, API et tests.

### 8.2 Permissions et scope

Constat :

- Plusieurs controllers utilisent des policies ou un trait de scope.
- `ActionTrackingWebController` contient aussi des methodes manuelles comme `canReadAction`, `canManageAction`, `canTrackWeekly`.

Impact :

- Les droits peuvent diverger entre API, web, dashboard et exports.
- Les tests deviennent difficiles a maintenir.

Recommandation :

- Centraliser les permissions action dans une policy/service unique.
- Garder les controllers maigres : validation, appel service, reponse.

### 8.3 Exercice courant

Constat :

- L'application contient un contexte d'exercice et des migrations recentes de rattachement.
- Il faut verifier que toutes les listes, exports, dashboards et formulaires filtrent bien sur l'exercice courant.

Risque :

- Melange de donnees historiques et courantes dans dashboard/reporting/actions.

Recommandation :

- Ajouter des tests par module pour confirmer le filtrage exercice.

### 8.4 Donnees anciennes et migrations

Constat :

- Le code porte des traces de l'ancien modele : axes/objectifs PAS/PAO, CRUD KPI/justificatifs, action creation directe, anciens types de suivi.

Risque :

- Les anciennes donnees peuvent avoir plusieurs champs concurrents renseignes.
- Les vues peuvent afficher deux interpretations d'une meme action.

Recommandation :

- Prevoir une migration ou commande de normalisation :
  - remplir `type_suivi`/`mode_evaluation` depuis la source prioritaire ;
  - vider ou ignorer les champs legacy contradictoires ;
  - journaliser les lignes ambiguës.

## 9. Textes, orthographe et encodage

Constats :

- Beaucoup de textes visibles sont non accentues : `Acces non autorise`, `Saisie gelee`, `realisee`, `traceabilite`, etc.
- Certains fichiers JS contiennent des identifiants suspects comme `colorAccèssor`, qui peuvent etre volontaires mais merite verification.
- Les libelles varient entre `Reset`, `Reinitialiser`, `Réinitialiser`, `Valider`, `Approuver`.

Impact :

- Interface moins professionnelle.
- Tests fragiles si les libelles changent page par page.

Recommandation :

- Creer un inventaire des textes visibles.
- Corriger les accents et libelles dans les vues et messages de controllers.
- Eviter de toucher aux noms techniques de routes, variables et colonnes sauf migration planifiee.

## 10. Priorites de correction recommandees

### P0 - A traiter avant tout

1. Corriger le calcul de progression des actions afin que les tests unitaires repassent.
2. Corriger la divergence du formulaire PTA signalee par le test feature.
3. Supprimer ou reconnecter les vues qui appellent des routes absentes.
4. Corriger les liens `password.request` et `register`.
5. Normaliser la regle du type de suivi actif pour eviter le double rendu.

### P1 - Stabilisation fonctionnelle

1. Centraliser les transitions de statut et permissions des actions.
2. Ajouter des confirmations a toutes les actions sensibles.
3. Unifier sidebar, navbar, boutons, champs de recherche, cartes et tableaux.
4. Stabiliser la palette officielle autour de `#3996d3`.
5. Clarifier la frontiere entre referentiel et super admin organisation.

### P2 - Finition et dette UI

1. Nettoyer les textes visibles, accents et libelles.
2. Reduire le JS/CSS inline du layout admin.
3. Harmoniser les dashboards, exports et tableaux.
4. Ajouter des tests navigateur ou feature pour les workflows critiques.

## 11. Checklist de verification manuelle

A faire apres corrections :

- Connexion et liens secondaires auth.
- Ouverture/fermeture sidebar en desktop, tablette, mobile.
- Sidebar retractee sans champ recherche.
- Logo lisible sur fond blanc.
- Navbar compacte, recherche, notifications, messagerie, profil.
- Dark/light mode sur toutes les pages principales.
- Listes PAS, PAO, PTA : filtre, ajout, modification, suppression, validation.
- Formulaires PAS, PAO, PTA : champs requis, erreurs, anciens champs caches.
- Actions : index, detail, suivi agent, financement DAF.
- Suivi quantitatif seul.
- Suivi qualitatif seul.
- Suivi mixte sans doublon contradictoire.
- Justificatifs : upload, download, suppression.
- Reporting : filtres, exports PDF/Word/Excel/CSV.
- Alertes : lecture, detail, filtres.
- Referentiel : directions, services, utilisateurs.
- Messagerie : envoi, lecture, piece jointe.
- Super admin : modules, organisation, roles, workflow, templates, snapshots.
- Confirmations des actions sensibles.
- Responsive mobile/tablette/desktop.

## 12. Conclusion

L'application a une base fonctionnelle avancee, mais elle souffre d'une transition inachevee entre anciens modules et nouvelle architecture. Les incoherences les plus dangereuses ne sont pas seulement visuelles : elles touchent les routes, le calcul de progression, les workflows d'action et la determination du type de suivi.

La prochaine phase devrait etre une correction en trois temps :

1. rendre l'application techniquement coherente : tests P0, routes, vues orphelines ;
2. rendre la logique metier univoque : suivi action, statuts, permissions ;
3. rendre l'interface uniforme : design system, textes, boutons, formulaires, confirmations.
