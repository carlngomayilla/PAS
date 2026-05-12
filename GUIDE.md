# Guide de navigation — ANBG e-Pilotage

Ce fichier explique comment le projet est organisé, où trouver chaque fonctionnalité,
et comment les différentes parties s'articulent. Il est conçu pour qu'une personne
non-développeur puisse s'orienter dans le code.

---

## Table des matières

1. [Vue d'ensemble](#1-vue-densemble)
2. [Organisation des dossiers](#2-organisation-des-dossiers)
3. [Les modules métier](#3-les-modules-métier)
4. [Où trouver quoi](#4-où-trouver-quoi)
5. [Le cycle de validation (workflow)](#5-le-cycle-de-validation-workflow)
6. [Les rôles utilisateurs](#6-les-rôles-utilisateurs)
7. [Les fichiers les plus importants](#7-les-fichiers-les-plus-importants)
8. [Glossaire des termes techniques](#8-glossaire-des-termes-techniques)

---

## 1. Vue d'ensemble

**e-Pilotage** est une application web de suivi de la performance institutionnelle
pour l'ANBG (Agence Nationale des Bourses du Gabon).

Elle permet de :
- Définir les objectifs stratégiques pluriannuels (**PAS**)
- Décliner ces objectifs en plans opérationnels annuels par direction (**PAO**)
- Planifier le travail par service sur l'année (**PTA**)
- Créer, suivre et valider les **Actions** concrètes
- Mesurer la performance via des **indicateurs KPI**
- Générer des **rapports** et des **alertes** automatiques

---

## 2. Organisation des dossiers

```
ANBG-PAS/
│
├── app/                          ← Toute la logique PHP de l'application
│   ├── Http/
│   │   ├── Controllers/          ← Ce qui se passe quand on clique sur un bouton
│   │   │   ├── Web/              ← Pages web (interfaces utilisateur)
│   │   │   └── Api/              ← API pour les appels JSON (usage interne)
│   │   ├── Requests/             ← Règles de validation des formulaires
│   │   └── Resources/            ← Format des données renvoyées par l'API
│   │
│   ├── Models/                   ← Les "objets" de l'application (Action, PTA, User...)
│   │
│   └── Services/                 ← La logique métier complexe
│       ├── Actions/              ← Calcul de progression, validation, suivi
│       ├── Analytics/            ← Statistiques et reporting
│       ├── Alerting/             ← Détection et envoi d'alertes
│       ├── Exports/              ← Génération de fichiers Excel, Word, PDF
│       ├── Notifications/        ← Notifications in-app et e-mails
│       ├── Planning/             ← Règles du workflow PAS → PAO → PTA → Action
│       └── Security/             ← Stockage sécurisé des justificatifs
│
├── database/
│   ├── migrations/               ← Historique de la structure de la base de données
│   └── seeders/                  ← Données de départ pour les tests
│
├── resources/
│   ├── views/                    ← Les pages HTML de l'application (templates Blade)
│   │   ├── layouts/              ← Structure commune (entête, sidebar, pied de page)
│   │   ├── components/           ← Éléments réutilisables (boutons, cartes, badges...)
│   │   ├── workspace/            ← Pages principales de l'espace de travail
│   │   │   ├── actions/          ← Pages liées aux actions
│   │   │   ├── pas/              ← Pages du Plan d'Actions Stratégique
│   │   │   ├── pao/              ← Pages du Plan d'Actions Opérationnel
│   │   │   ├── pta/              ← Pages du Plan de Travail Annuel
│   │   │   └── super_admin/      ← Pages d'administration avancée
│   │   └── emails/               ← Modèles d'e-mails envoyés par l'application
│   │
│   ├── css/app.css               ← Toute l'apparence visuelle (couleurs, mise en page)
│   └── js/                       ← Comportements dynamiques des pages
│       ├── app.js                ← Point d'entrée principal (importe tous les modules JS)
│       ├── admin-shell.js        ← Sidebar, thème, dialogues de confirmation, horloge
│       ├── dashboard-render.js   ← Rendu des graphiques du tableau de bord
│       ├── ui-enhancements.js    ← Spinner, messages flash, pagination
│       └── messaging-init.js     ← Messagerie interne temps réel
│
├── routes/web.php                ← Toutes les URLs de l'application et leur traitement
├── config/                       ← Configuration (base de données, mail, cache...)
└── tests/                        ← Tests automatisés qui vérifient que tout fonctionne
```

---

## 3. Les modules métier

### Hiérarchie de planification

```
PAS  (Plan d'Actions Stratégique)     ← Niveau institutionnel, pluriannuel
 └── PAO  (Plan d'Actions Opérationnel)  ← Niveau direction, annuel
      └── PTA  (Plan de Travail Annuel)     ← Niveau service, annuel
           └── Action                           ← Niveau agent, opérationnel
                └── Sous-action (optionnelle)
                └── KPI (indicateur de résultat)
                └── Justificatifs (preuves d'exécution)
```

### Description de chaque module

| Module | Qui le gère | Ce que ça fait |
|--------|-------------|----------------|
| **PAS** | DG / Admin | Définit les axes stratégiques et objectifs sur plusieurs années |
| **PAO** | Direction | Décline le PAS en objectifs opérationnels pour une direction, une année |
| **PTA** | Chef de service | Planifie les actions d'un service pour l'année |
| **Action** | Agent | Décrit une tâche concrète avec cible, dates, responsable, budget |
| **KPI** | Système | Indicateurs de performance calculés automatiquement |
| **Reporting** | Tous | Synthèses visuelles et exports (Excel, Word, PDF) |
| **Alertes** | Système | Détection automatique des actions en retard ou sous-performantes |

---

## 4. Où trouver quoi

### Je veux modifier une page visible

→ Aller dans `resources/views/workspace/`  
→ Le sous-dossier correspond au module (`actions/`, `pas/`, `pta/`...)  
→ `index.blade.php` = la liste, `form.blade.php` = le formulaire, `suivi.blade.php` = le détail

### Je veux changer un calcul ou une règle métier

→ Aller dans `app/Services/`  
→ `Actions/ActionProgressService.php` = calcul de progression des actions  
→ `Actions/ActionTrackingService.php` = statuts, validation, workflow des actions  
→ `Planning/PlanningWorkflowRulesService.php` = règles PAS → PAO → PTA

### Je veux modifier ce qui s'affiche dans le formulaire d'une action

→ `resources/views/workspace/actions/form.blade.php`  
→ `app/Http/Requests/StoreActionRequest.php` = règles de validation à la création  
→ `app/Http/Requests/UpdateActionRequest.php` = règles de validation à la modification

### Je veux changer les URLs de l'application

→ `routes/web.php`

### Je veux changer l'apparence (couleurs, mise en page)

→ `resources/css/app.css`  
→ Les variables de couleur sont au début du fichier dans `:root { ... }`

### Je veux modifier un e-mail envoyé par l'application

→ `resources/views/emails/`

### Je veux créer ou modifier un utilisateur, direction ou service

→ Dans l'interface : menu **Super Administration → Organisation & Utilisateurs**  
→ Dans le code : `app/Http/Controllers/Web/SuperAdminWebController.php`

---

## 5. Le cycle de validation (workflow)

Chaque document passe par ces statuts dans l'ordre :

```
brouillon  →  soumis  →  validé  →  verrouillé
   (draft)     (soumis     (approuvé   (figé, lecture
                pour        par         seule)
                validation) supérieur)
```

Pour **retourner en brouillon** : bouton "Retour brouillon" (avec motif obligatoire).

Les **Actions** ont en plus un statut de validation spécifique :
```
non_soumise  →  soumise_chef  →  validée_chef  →  validée_direction
                    ↓ (rejet)        ↓ (rejet)
               rejetée_chef    rejetée_direction
```

Les règles qui gouvernent ces transitions sont dans :
`app/Services/Planning/PlanningWorkflowRulesService.php`

---

## 6. Les rôles utilisateurs

| Rôle (code) | Nom affiché | Ce qu'il peut faire |
|-------------|-------------|---------------------|
| `super_admin` | Super Administrateur | Tout, y compris configuration système |
| `admin` | Administrateur | Gestion des utilisateurs, validation PAS/PAO |
| `dg` | Directeur Général | Approbation des PAS et PAO |
| `direction` | Chef de direction | Gestion PTA et validation PAO de sa direction |
| `service` | Chef de service | Création et gestion PTA et actions de son service |
| `agent` | Agent | Suivi et exécution de ses actions |
| `lecture` | Consultation | Lecture seule, aucune modification |
| `daf` | DAF | Revue des financements des actions |

Les rôles sont définis dans `app/Models/User.php` (constantes `ROLE_*`).

---

## 7. Les fichiers les plus importants

| Fichier | Rôle |
|---------|------|
| `app/Models/Action.php` | Le modèle central — définit toutes les propriétés d'une action |
| `app/Models/User.php` | Utilisateurs et rôles |
| `app/Services/Actions/ActionTrackingService.php` | Statuts, validation, alertes des actions |
| `app/Services/Actions/ActionProgressService.php` | Calcul de la progression (%) |
| `app/Services/Analytics/KpiAggregatorService.php` | Calcul des KPI globaux |
| `routes/web.php` | Toutes les URLs → toutes les actions possibles |
| `resources/css/app.css` | Tout le style visuel de l'application |
| `resources/js/admin-shell.js` | Sidebar, thème sombre/clair, dialogues, horloge |
| `database/seeders/InstitutionalPasSeeder.php` | Données de démonstration |
| `config/logging.php` | Configuration des journaux d'erreurs |

---

## 8. Glossaire des termes techniques

| Terme | Signification pratique |
|-------|----------------------|
| **Controller** | Fichier PHP qui reçoit les clics/soumissions et décide quoi faire |
| **Model** | Fichier PHP qui représente une table de la base de données |
| **Service** | Fichier PHP qui contient une logique complexe réutilisable |
| **Blade** | Langage des fichiers `.blade.php` — c'est du HTML avec du PHP simplifié |
| **Migration** | Fichier qui décrit une modification de la structure de la base de données |
| **Seeder** | Fichier qui insère des données de test dans la base de données |
| **Route** | Association entre une URL et le code qui la traite |
| **Middleware** | Vérification automatique avant d'accéder à une page (ex: "est-il connecté ?") |
| **Request** | Fichier qui définit les règles de validation d'un formulaire |
| **KPI** | Indicateur clé de performance — calculé automatiquement par le système |
| **PAS** | Plan d'Actions Stratégique — document pluriannuel de haut niveau |
| **PAO** | Plan d'Actions Opérationnel — déclinaison annuelle par direction |
| **PTA** | Plan de Travail Annuel — organisation concrète d'un service |
| **Workflow** | Circuit de validation par lequel passe un document avant d'être actif |
| **Justificatif** | Pièce jointe (PDF, image...) prouvant l'exécution d'une action |
| **Alerte** | Notification automatique déclenchée quand une action décroche |
