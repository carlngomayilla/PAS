# Formules de calcul des taux — règle métier en vigueur

> **Statut** : règle appliquée dans toute l'application depuis le 2026-08-04, conformément à la note métier de référence. L'historique de l'ancienne règle est conservé en annexe.

---

## 1. Vue d'ensemble

Le PAS est mesuré par **six indicateurs**, répartis en deux familles qu'il ne faut pas confondre.

### Famille 1 — Performance progressive (niveaux 1 à 4)

Mesure le **niveau d'atteinte des résultats**. Une action réalisée à 80 % contribue pour 80 %.

Chaque enfant a le **même poids** et sa **cible de performance vaut 100 %**. Le taux d'un niveau est donc la moyenne des taux de ses enfants.

### Famille 2 — Comptage d'actions terminées (niveaux 5 et 6)

Mesure **combien d'actions sont effectivement terminées**. Une action réalisée à 80 % **n'est pas** comptée.

> **Performance du PAS ≠ Taux d'exécution du PAS.** Les deux répondent à des questions différentes et doivent être lues séparément.

---

## 2. Niveau 1 — Sous-action

`PtaOfficialCalculationService::subActionResult()`

| Type d'indicateur | Formule |
| --- | --- |
| Quantitatif | `taux = quantité_réalisée ÷ cible_prévue × 100` |
| Non quantitatif (livrable) | `taux = 100` si le livrable est déposé, sinon `0` — implémenté comme `cible = 1`, `réalisé = 1 ou 0` |
| Mixte | `taux = (taux_quantitatif + taux_livrable) ÷ 2` — moyenne **des pourcentages**, pas des quantités |

**Cas non paramétré** : si `cible ≤ 0`, la sous-action renvoie `rate = null`, `is_configured = false` et elle est **exclue** de toutes les agrégations (statut « À paramétrer »).

Bornage : `taux` est ramené dans `[0 ; 100]` puis arrondi à 2 décimales.

---

## 3. Niveau 2 — Action

`PtaOfficialCalculationService::actionResult()`

L'action combine ses propres cibles et celles de ses sous-actions :

1. Si l'action porte une **cible quantitative** → une composante `quantité_réalisée ÷ quantité_cible × 100`.
2. Si l'action porte un **livrable attendu** → une composante `100` ou `0`.
3. Les **sous-actions paramétrées** sont ajoutées comme composantes.

Puis :

| Situation | Formule appliquée |
| --- | --- |
| Une seule composante | Le taux de cette composante |
| Action **mixte** avec plusieurs composantes | **Moyenne des pourcentages** — `Σ taux ÷ nombre de composantes` |
| Autres cas | **Pondération par la cible** — `Σ réalisé ÷ Σ cible × 100` |
| Aucune composante paramétrée | `rate = null` → action exclue des agrégations |

> **Point d'attention** : une action mixte utilise la moyenne des pourcentages, alors qu'une action quantitative utilise la pondération par la cible. Deux actions du même périmètre ne sont donc pas calculées de la même manière.

### Seuil de complétude

`seuil_minimum` de l'action, **80 % par défaut**. Il ne modifie pas le taux : il sert uniquement à décider du statut.

| Condition | Statut |
| --- | --- |
| `taux ≥ seuil` | Réalisée |
| `0 < taux < seuil` | Partiellement réalisée |
| `taux = 0` | Non démarrée |
| `taux = null` | À paramétrer |

---

## 4. Niveaux 3 à 5 — Objectif opérationnel, objectif stratégique, axe

`PtaOfficialCalculationService::targetWeightedRows()`

Une seule et même formule, appliquée en cascade :

```
taux du niveau = ( Σ taux des enfants ) ÷ ( 100 × nombre d'enfants ) × 100
               = moyenne des taux des enfants
```

Chaque enfant a le même poids et sa cible de performance vaut 100 %. **Une action de cible 1 000 pèse autant qu'une action de cible 10.**

Les enfants non paramétrés (cible absente) sont exclus du calcul. Si aucun enfant n'est paramétré, le niveau renvoie « À paramétrer ».

Les cumuls `Σ cible` et `Σ réalisé` restent calculés et affichés à titre informatif, mais **n'entrent plus dans le calcul du taux**.

### Exemple de référence

| Niveau | Composition | Calcul | Taux |
| --- | --- | --- | --- |
| OP1 | A1 (80 %), A2 (100 %) | `(80 + 100) ÷ 200 × 100` | **90 %** |
| OP2 | A3 (60 %), A4 (100 %) | `(60 + 100) ÷ 200 × 100` | **80 %** |
| OP3 | A5 (100 %), A6 (100 %) | `(100 + 100) ÷ 200 × 100` | **100 %** |
| OP4 | A7 (50 %), A8 (100 %) | `(50 + 100) ÷ 200 × 100` | **75 %** |
| OS1 | OP1, OP2 | `(90 + 80) ÷ 200 × 100` | **85 %** |
| OS2 | OP3, OP4 | `(100 + 75) ÷ 200 × 100` | **87,5 %** |
| Axe 1 | OS1, OS2 | `(85 + 87,5) ÷ 200 × 100` | **86,25 %** |

---

## 5. Niveau 6 — Indicateurs du PAS

Ces deux indicateurs reposent sur un **comptage d'actions terminées**, pas sur la performance partielle. Une action est comptée comme réalisée uniquement lorsqu'elle atteint **100 %**.

### Taux d'exécution du PAS

`PtaOfficialCalculationService::executionRate()`

```
taux d'exécution = ( actions échues réalisées ) ÷ ( actions échues ) × 100
```

Une action est **échue** lorsque son échéance est atteinte ou dépassée à la date de suivi. Réponse à la question : *parmi ce qui aurait déjà dû être terminé, quelle part l'est effectivement ?*

Exemple de référence : 6 actions échues (A1 à A6), 4 réalisées (A2, A4, A5, A6) → `4 ÷ 6 × 100` = **66,67 %**.

### Taux d'avancement global du PAS

`PtaOfficialCalculationService::globalCompletionRate()`

```
taux d'avancement global = ( actions réalisées ) ÷ ( actions programmées ) × 100
```

Toutes les actions du périmètre comptent, échues ou non. Réponse à la question : *quelle part du plan est terminée ?*

Exemple de référence : 5 actions réalisées (A2, A4, A5, A6, A8 — cette dernière en avance) sur 8 → `5 ÷ 8 × 100` = **62,5 %**.

### Performance moyenne des axes

Moyenne des taux des axes stratégiques (formule du niveau 4). Exemple de référence : **86,25 %**.

---

## 6. Où chaque indicateur s'affiche

| Écran | Indicateur | Source |
| --- | --- | --- |
| Tableau de bord — carte 1 | Taux d'exécution | `pasCompletionIndicators()` → `executionRate()` |
| Tableau de bord — carte 2 | Avancement global | `pasCompletionIndicators()` → `globalCompletionRate()` |
| Tableau de bord — carte 3 | Performance moyenne des axes | `pasCompletionIndicators()` → `targetWeightedRows()` |
| Vue synthétique des axes | Taux par axe, objectif, PAO/PTA, action | `synthesisActionsProgress()` → `targetWeightedRows()` |
| Suivi PTA officiel | Taux par niveau | `PtaSuiviService::groupRows()` → `targetWeightedRows()` |
| Reporting PTA, exports PDF/Excel | Idem Suivi PTA | même payload |

Toutes ces surfaces passent désormais par `PtaOfficialCalculationService` : **un seul jeu de formules pour toute l'application**.

Les sous-titres des cartes affichent le comptage brut (`4 action(s) échue(s) réalisée(s) sur 6`), pour que le chiffre soit vérifiable d'un coup d'œil.

---

## 7. Colonnes de taux stockées sur `actions`

`ActionProgressService::calculateMetrics()` alimente ces colonnes à chaque recalcul :

| Colonne | Formule | Remarque |
| --- | --- | --- |
| `avancement_operationnel` | `sous-actions terminées ÷ total sous-actions × 100` | `0` si l'action n'a pas de sous-action |
| `taux_atteinte_cible` | `quantité_réalisée ÷ cible × 100` | `0` si pas de cible quantitative |
| `progression_reelle` | cible > 0 → `taux_atteinte_cible` ; sinon sous-actions → `avancement_operationnel` ; sinon progression déclarée | Valeur de référence |
| `taux_global` | identique à `progression_reelle` | **redondant** |
| `taux_realisation_global` | identique à `progression_reelle` | **redondant** |
| `progression_theorique` | `temps écoulé ÷ durée totale × 100` (bornée à 100) | Avancement attendu à la date du jour |
| `reste_a_realiser` | `max(cible − réalisé, 0)` | En unités, pas en pourcentage |
| `taux_depassement` | `(réalisé − cible) ÷ cible × 100` si `réalisé > cible` | `0` sinon |

### Quantité réalisée retenue

`ActionPerformanceService::realizedQuantity()` prend le **maximum** de trois sources :

```
max( quantite_realisee stockée , Σ semaines renseignées , Σ quantités des sous-actions )
```

---

## 8. KPI (indicateurs de pilotage, distincts des taux de réalisation)

`ActionPerformanceService`

| KPI | Formule |
| --- | --- |
| `kpi_performance` | `= calculateRealProgress()` — identique à la progression réelle |
| `kpi_delai` | `100` si soumis avant échéance ; sinon `100 − (jours de retard ÷ durée prévue × 100)`, borné à `[0 ; 100]`. Sans `date_debut` : `100 − (jours de retard × 5)`. Non soumis et échéance dépassée : `0` |
| `kpi_global` | `kpi_performance × 0,70 + kpi_delai × 0,30` |

Pondération officielle : **70 % performance / 30 % délai**. Le KPI conformité a été supprimé de la règle métier active.

`kpi_global` vaut `0` tant que l'action n'a ni progression ni soumission. Les KPI d'une action suspendue sont **gelés** (relus depuis `action_kpis` au lieu d'être recalculés).

---

## 9. Points de vigilance restants

| # | Point | Emplacement |
| --- | --- | --- |
| 1 | Au niveau de l'action, une action **mixte** fait la moyenne de ses pourcentages alors qu'une action **quantitative** pondère par la cible. Deux actions du même objectif ne sont donc pas calculées de la même manière. | `actionResult()` |
| 2 | Trois colonnes stockées portent la même valeur (`progression_reelle`, `taux_global`, `taux_realisation_global`) et alimentent une cascade à cinq entrées. | `ActionProgressService`, `dashboardPtaProgressRate()` |
| 3 | Un livrable est compté `100 %` dès qu'un justificatif existe, **sans vérification de validation**. Un document rejeté produit donc 100 %. | `actionDeliverableCompleted()` |
| 4 | `institutionWeighted()` implémente une méthode pondérée par un poids explicite, non branchée sur la chaîne PAS. | `PtaOfficialCalculationService` |
| 5 | Le `seuil_minimum` (80 % par défaut) décide du **statut** affiché, pas du taux — et il est distinct du seuil de 100 % qui décide si une action est comptée comme terminée dans les indicateurs du PAS. Une action à 85 % est donc affichée « Réalisée » sans être comptée dans le taux d'avancement global. | `StatutRealisation::fromRate()` |
| 6 | Le module Reporting compte ses actions terminées sur le **statut** (`achevé`), pas sur le seuil de 100 %. Ses `taux_realisation` peuvent donc différer légèrement du taux d'avancement global du PAS. | `ReportingAnalyticsService::completionRate()` |

---

## 10. Annexe — règle antérieure au 2026-08-04

Avant cette date, les niveaux 3 à 6 utilisaient une **pondération par la cible en unités** :

```
taux = ( Σ réalisé des enfants ) ÷ ( Σ cible des enfants ) × 100
```

Une action de cible 1 000 pesait donc dix fois plus qu'une action de cible 100. En parallèle, le tableau de bord utilisait une moyenne arithmétique des taux d'action, ce qui produisait deux valeurs différentes pour un même périmètre (mesuré sur la base locale : **4,15 %** contre **2,78 %**).

Les deux chaînes ont été unifiées sur la règle décrite dans ce document.

---

## 11. Références de code

| Élément | Fichier |
| --- | --- |
| Chaîne officielle (tous niveaux) | `app/Services/PtaOfficialCalculationService.php` |
| Colonnes de taux stockées | `app/Services/Actions/ActionProgressService.php` |
| Progression réelle, quantité réalisée, KPI | `app/Services/ActionPerformanceService.php` |
| Statuts et seuils | `app/Enums/StatutRealisation.php` |
| Indicateurs PAS du tableau de bord | `app/Http/Controllers/DashboardController.php` (`pasCompletionIndicators()`, `synthesisActionsProgress()`) |
| Assemblage de la hiérarchie PTA | `app/Services/PtaSuiviService.php` (`groupRows()`) |
