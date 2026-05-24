# Spécification canonique du workflow PAS ANBG

**Statut** : référence métier officielle  
**Date** : 22 mai 2026  
**Source** : validation Carl (PAS ANBG)  
**Portée** : toute évolution du code doit s'aligner sur ce document.

---

## 1. Logique générale

Chaîne métier complète :

```
PAS → PAO → PTA → Actions → Sous-actions → Suivi → Validations → KPI → Rapports → Audit
```

Le **PAS** est validé officiellement **avant** son entrée dans l'application. L'application ne valide pas le PAS, elle suit son exécution opérationnelle.

---

## 2. Structure organisationnelle

L'application reconnaît **4 directions principales** :

| Direction      | Services / unités          |
| -------------- | -------------------------- |
| Cabinet du DG  | SCIQ, UCAS, Collaborateurs |
| DSIC           | SIRS, CRP, GDS             |
| DAF            | AJARH, AMG, SFC            |
| DS             | EN, ENB, Planification     |

**Vue globale agence** réservée à : DG, DGA, Cabinet/Collaborateurs, SCIQ, Planification, Super Admin. UCAS reste opérationnelle, sans vue globale par défaut.

---

## 3. Rôle du DG

Le DG **ne valide pas** les actions courantes. Il intervient uniquement pour :
- arbitrages critiques
- actions stratégiques
- blocages majeurs
- financements critiques transmis par la DAF
- rapports consolidés
- décisions exceptionnelles

Pour les actions ordinaires, la validation du **chef de service** suffit.

---

## 4. Module PAS

### Créateurs : SCIQ, Planification (Super Admin en correction technique exceptionnelle).

### Formulaire PAS

| Champ                  | Règle       |
| ---------------------- | ----------- |
| Titre du PAS           | obligatoire |
| Période début          | obligatoire |
| Période fin            | obligatoire |
| Axes stratégiques      | dynamiques  |
| Objectifs stratégiques | dynamiques sous chaque axe |

Chaque objectif stratégique contient : libellé + date d'échéance.

### Statuts : `actif → cloture → archive`

### Modification
- PAS non utilisé : libre
- PAS utilisé : ajout autorisé, suppression d'éléments utilisés interdite

### Clôture
Rapport d'anomalies obligatoire : PAO ouverts, PTA ouverts, actions en cours, retards, validations en attente, KPI incomplets. SCIQ/Planification peut bloquer, autoriser ou clôturer avec justification.

### Archivage : manuel après clôture.

---

## 5. Module PAO (Plan d'Action Opérationnels)

### Règle structurelle

```
1 direction = 1 PAO par exercice
```

Le PAO est **directionnel**. Le service est porté par **chaque objectif opérationnel**, **pas** par le PAO racine.

### Créateur : Directeur de la direction

SCIQ/Planification : contrôle, consolidation, observation, correction avec accord du directeur, audit.

### Statuts : `en_cours → valide → cloture → archive`

Validation **automatique** lorsque tous les champs obligatoires sont complets.

### Objectif opérationnel

| Champ                         | Règle       |
| ----------------------------- | ----------- |
| Objectif stratégique lié      | obligatoire |
| Service concerné              | obligatoire |
| Libellé objectif opérationnel | obligatoire |
| Date d'échéance               | obligatoire |

### Transmission
Après validation automatique : objectifs transmis aux chefs concernés + notification + récapitulatif au directeur + visibilité SCIQ/Planification.

### Clôture : rapport d'anomalies obligatoire.

### Archivage : automatique après durée paramétrable (Super Admin).

---

## 6. Module PTA

### Règle

```
1 service ou unité = 1 PTA par exercice
```

Le chef **ne sélectionne pas** son service. L'application détecte le rattachement via `user.service_id`.

### Création
À l'ouverture de l'espace PTA par le chef :
1. Détection du service/unité
2. Récupération des objectifs opérationnels transmis
3. Proposition de création du PTA
4. Affichage de chaque objectif avec sa provenance (lecture seule) : PAS, Axe stratégique, Objectif stratégique, Objectif opérationnel, Échéance
5. Sous chaque objectif, formulaire de création des actions

### Contrainte de date

```
date_fin_action ≤ date_echeance_objectif_operationnel
```

### Statuts : `en_cours → cloture → archive`

**Pas de statut « validé »** pour le PTA.

### Clôture : rapport d'anomalies obligatoire.

### Archivage : automatique après durée paramétrable.

---

## 7. Module Actions

### Formulaire

| Bloc                   | Champs                                           |
| ---------------------- | ------------------------------------------------ |
| Informations générales | libellé, description, date début, date fin       |
| Responsables           | un ou plusieurs RMO / agents                     |
| Mode d'exécution       | quantitatif ou sous-actions                      |
| Cible                  | cible globale **ou** cibles trimestrielles (pas les deux) |
| Justificatif attendu   | oui / type attendu                               |
| Financement            | oui/non + nature, montant, pièce si oui          |
| Risque                 | oui/non + description, mesures correctives si oui |
| Ressources             | matériel, main-d'œuvre, autre (champs texte simples) |

### Modes d'exécution
- **Quantitatif** : cible quantitative + quantité réalisée + unité
- **Sous-actions** : tâches confiées à différents RMO (chacune peut avoir ou non une quantité)

### Type de cible
- **Cible globale** : pourcentage unique
- **Cibles trimestrielles** : T1, T2, T3, T4

Pas d'option combinée.

---

## 8. Sous-actions

### Statuts
```
non_demarre → en_cours → realisee → en_attente_validation_chef → validee_chef
                                                                   ↘ rejetee_a_corriger
```

Quand un RMO clique sur « Marquer comme réalisée » → sous-action en attente validation chef (pas validée définitivement).

### Droits RMO
- Voit son action et sa sous-action
- Voit les sous-actions des autres RMO en lecture seule
- Écrit uniquement sur ses propres éléments

---

## 9. Suivi agent / RMO

Chaque saisie de suivi requiert obligatoirement :

| Champ                   | Obligatoire |
| ----------------------- | ----------- |
| Pièce justificative     | oui         |
| Commentaire             | oui         |
| Difficultés rencontrées | oui         |

Sans difficulté → écrire « Aucune difficulté rencontrée. »

---

## 10. Calcul de l'avancement

Pourcentage **calculé automatiquement**, jamais saisi.

### Action quantitative
```
avancement = quantite_realisee / quantite_cible × 100
```

### Action avec sous-actions (avec quantités)
```
avancement = Σ quantites_realisees / Σ quantites_cibles × 100
```

### Action avec sous-actions (sans quantités)
```
avancement = sous_actions_validees / total_sous_actions × 100
```

### Démarrage automatique
Action passe de `non_demarre` → `en_cours` dès qu'un suivi réel existe : quantité réalisée, commentaire, difficulté, justificatif, sous-action soumise.

---

## 11. Statuts de l'action

```
non_demarre
en_cours
realisee
en_attente_validation_chef
validee_chef
en_attente_directeur
validee_direction
rejetee_a_corriger
```

### Action ordinaire
```
Agent/RMO → Chef → Action terminée
```

### Action sensible
```
Agent/RMO → Chef → Directeur → (SCIQ/Planification | DG | DAF selon contexte)
```

**Action sensible** = critique, stratégique, financée, à risque, bloquée, très en retard, ou signalée par SCIQ/Planification.

---

## 12. SCIQ / Planification

Rôle de **contrôle transversal**. Peut :
- consulter, signaler une anomalie, demander correction
- ajouter une observation
- bloquer une action incohérente (de façon ciblée)
- lever un blocage

Ne modifie pas directement les actions à la place du chef, sauf procédure exceptionnelle.

### Blocages ciblés

| Anomalie                | Blocage                       |
| ----------------------- | ----------------------------- |
| Justificatif manquant   | validation bloquée            |
| Commentaire absent      | soumission bloquée            |
| Date incohérente        | enregistrement bloqué         |
| Financement incomplet   | circuit DAF bloqué            |
| KPI incohérent          | reporting bloqué ou anomalie  |

---

## 13. Tâches personnelles

Chaque utilisateur dispose de :
1. Un bloc **Mes tâches** dans le dashboard
2. Un module séparé **Mes tâches**

Chaque tâche : responsable, délai, statut, criticité, lien vers l'élément, impact sur le score personnel.

### Délais

| Acteur               | Délai   |
| -------------------- | ------- |
| Chef                 | 48h     |
| Directeur            | 48h     |
| SCIQ / Planification | 48h     |
| DG                   | 48h     |
| Directeur DAF        | 3 jours |

Retard de validation **ne pénalise pas l'agent** qui a soumis à temps — il pénalise le valideur.

---

## 14. KPI global

Mixte, pondéré :

| Composante         | Poids |
| ------------------ | ----- |
| Avancement réel    | 50 %  |
| Validation obtenue | 25 %  |
| Respect des délais | 15 %  |
| Cible atteinte     | 10 %  |

Les 25 % de validation sont accordés **uniquement quand le circuit requis est terminé**. Le délai distingue exécution agent / validation hiérarchique.

---

## 15. Score personnel

| Composante             | Poids |
| ---------------------- | ----- |
| Tâches traitées        | 35 %  |
| Respect des délais     | 30 %  |
| Qualité du traitement  | 25 %  |
| Criticité / importance | 10 %  |

### Qualité
Évaluée par appréciation : Insuffisant, Moyen, Bon, Très bon, Excellent. Par supérieur hiérarchique direct et par SCIQ/Planification pour contrôles transversaux.

### Criticité
Trois niveaux : Normale, Importante, Critique. Proposée auto par l'application, modifiable par profil habilité avec justification.

---

## 16. Dashboards

Chaque dashboard contient : KPI du périmètre + Mes tâches + score personnel + alertes importantes + graphiques + tableaux synthétiques.

```
Dashboard = périmètre autorisé + tâches + score personnel + alertes
```

---

## 17. Sidebars validées

### Agent / RMO
Dashboard · Mes actions · Corrections demandées · Notifications

**Mes actions** regroupe : actions, sous-actions, suivi, justificatifs, commentaires, difficultés.

### Chef service / unité (hors SCIQ)
Dashboard · Mes tâches · PTA · Actions · Validations · Agents/RMO · Reporting service · Notifications

### SCIQ / Planification
Dashboard global · Mes tâches · PAS · PAO · PTA · Actions · Contrôle · Reporting global · Mes actions · Notifications

### Directeur
Dashboard direction · Mes tâches · PAO · PTA des services · Suivi des actions · Services/Agents · Reporting direction · Notifications

### Directeur DAF
Idem Directeur + **Financement des actions** (actions à financer, financées, rejetées, compléments, transmis DG, cumuls automatiques).

### DG
Dashboard global · Mes tâches · Synthèse agence · Arbitrages · Financements critiques · Rapports consolidés · Alertes critiques · Notifications

### DGA / Cabinet / Collaborateurs
Dashboard global · Mes tâches · Synthèse agence · Supervision · Rapports · Mes actions · Alertes importantes · Notifications

### UCAS
Dashboard unité · Mes tâches · PTA · Actions · Validations · Agents/RMO · Reporting unité · Notifications

### Super Admin
Utilisateurs, rôles, permissions, directions, services, unités, exercices, workflows, délais, statuts, KPI, scores, notifications, exports, audit, maintenance.

---

## 18. Rapports

**Formats** : PDF + Excel uniquement.

Liste : Rapport PAS · Rapport PAO · Rapport PTA · Rapport Actions · Rapport KPI · Rapport Anomalies · Rapport Financement · Rapport Consolidé DG.

Détail dans le prompt technique (sections 15 du prompt Claude Code).

---

## 19. Alertes et notifications

### Niveaux : Info, Avertissement, Critique
### Déclenchement : automatique ou manuel (SCIQ/Planification)

Quand alerte créée : affichage dashboard + notification + tâche si action attendue.

### Statuts alerte : `ouverte → en_cours → cloturee`

---

## 20. Sécurité et accès

```
sidebar filtrée + routes protégées + données filtrées par périmètre métier
```

Un utilisateur ne doit jamais voir/exporter des données hors périmètre, même par URL directe.

Accès refusé → message clair + redirection dashboard + audit si tentative sensible.

---

## 21. Audit

Audit complet de tous les événements sensibles. Chaque trace contient : auteur, rôle, date/heure, module, action, ancienne valeur, nouvelle valeur, motif si nécessaire, IP si disponible.

Motif obligatoire pour : rejets, demandes correction, modifications éléments utilisés, blocages/levées, financements rejetés/transmis, criticité modifiée, intervention Super Admin, clôture avec anomalies.

---

## 22. Suppression et désactivation

### Suppression définitive
Réservée au Super Admin avec motif obligatoire + analyse d'impact + audit complet. Profils pouvant en faire la demande : chef, directeur, DAF, SCIQ, Planification, DG/DGA/Cabinet.

### Utilisateur désactivé
Désactivation + transfert des tâches ouvertes + conservation complète de l'historique.

### Changement de poste / rôle / service
Historique de rattachement + nouveau rattachement + transfert des tâches ouvertes.

---

## 23. Exercices et trimestres

### Exercice : année civile par défaut, dates modifiables par Super Admin.
### Exercice actif : défini par Super Admin.
### Trimestre : filtre affichage + recalcul KPI + contrôle cibles trimestrielles.

---

## 24. UX

### États vides : message clair + explication + action recommandée.
### Blocages métier : cause + champ concerné + correction attendue.

Exemple :
> Impossible de soumettre : la pièce justificative est obligatoire. Veuillez téléverser un justificatif avant de continuer.

---

## Plan d'exécution technique (10 lots)

| Lot | Périmètre | Statut |
| --- | --- | --- |
| 1 | RBAC + sidebars + routes protégées | À faire |
| 2 | PAO directionnel + PTA service/unité (correction modèle) | À faire |
| 3 | Actions + sous-actions + suivi agent | En cours (suivi hebdo supprimé ✓) |
| 4 | Validations + tâches + délais 48h | À faire |
| 5 | KPI 50/25/15/10 + scores personnels | À faire |
| 6 | Financement DAF/DG (9 statuts) | Partiel (déjà 7 statuts) |
| 7 | Alertes + notifications | À faire |
| 8 | Rapports PDF/Excel (8 rapports) | Partiel |
| 9 | Audit + suppression + archivage | Partiel |
| 10 | Tests et contrôle final | À faire |

---

_Fin du document de référence._
