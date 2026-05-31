# Workflow de suivi des actions — Spécification V2

> Document de référence pour la refonte du workflow de suivi opérationnel
> (branche `feature/reset-action-workflow`).
> Co-conçu et validé le 2026-05-31. Toute évolution doit mettre ce document à jour.

---

## Principe central

> **Le PTA définit. Le suivi applique. La validation officialise.**
>
> - Le formulaire **PTA** enregistre le type d'action, la cible, l'unité, le type de
>   seuil, les seuils d'alerte/critique, l'échéance, l'obligation de pièce justificative,
>   le responsable et les conditions de commentaire/difficulté.
> - Le module de **suivi** ne redéfinit jamais ces règles : il les applique et calcule
>   une **performance provisoire**.
> - La **validation chef** transforme la performance provisoire en **performance
>   officielle** (la seule comptée dans le reporting).

---

## 1. Le modèle pivot : `type_action`

Un seul champ structurant détermine tout le comportement :

| `type_action`     | Seuil principal      | Cible          | Calcul de performance        | Sous-actions |
| ----------------- | -------------------- | -------------- | ---------------------------- | ------------ |
| `quantitative`    | Numérique (t1-t4)    | quantité+unité | `réalisé / prévu`            | interdites   |
| `non_quantitative`| Binaire (0 / 100%)   | 1 pièce        | déposé = 100%                | interdites   |
| `composee`        | Pondéré              | via sous-actions | `Σ(perf_SA × poids)`       | obligatoires |

Le **type de cible** et le **type de seuil principal** sont **dérivés** de `type_action`
(décision : un seul champ pour éviter les incohérences de saisie).

---

## 2. Deux dimensions TRANSVERSES (toujours évaluées en plus du seuil principal)

### 2.1 Dimension temporelle (échéance)

Comparaison `date du jour` vs `date_echeance` :

| Situation                          | Statut délai     |
| ---------------------------------- | ---------------- |
| date du jour < échéance            | `dans_delai`     |
| date du jour proche de l'échéance  | `bientot_retard` |
| date du jour > échéance (non fini) | `en_retard`      |
| échéance dépassée + non démarrée   | `critique`       |
| report validé                      | nouvelle échéance|

⚠️ Une action à 100% **provisoire** n'est PAS considérée officiellement terminée tant
qu'elle n'est pas **validée par le chef**.

### 2.2 Dimension conformité

| Élément             | Condition                                       |
| ------------------- | ----------------------------------------------- |
| Pièce justificative | Obligatoire à la soumission (sauf déjà déposée) |
| Commentaire         | Obligatoire si `requires_comment = true`        |
| Difficulté          | Description obligatoire si `allows_difficulty` ET difficulté signalée |
| Validation chef     | Obligatoire pour officialiser                   |
| Motif de rejet      | Obligatoire en cas de rejet                     |

La conformité **bloque la soumission** si une condition n'est pas remplie.

---

## 3. Performance PROVISOIRE → OFFICIELLE

Deux champs séparés (décision validée) :

```
progress_percent           → recalculé à CHAQUE "Enregistrer" (agent)
official_progress_percent  → figé au "Valider" (chef)
```

**Seul `official_progress_percent` est compté dans le reporting / les statistiques.**
Cela évite qu'une action saisie à 100% mais non validée pollue les chiffres consolidés.

---

## 4. Cycle de vie (états)

```
non_demarre → en_cours → soumis (attente validation chef) → validé ✓
                  ↑                                            │
                  └────────── rejeté / à corriger ←────────────┘
```

- **non_demarre** : état initial après création PTA.
- **en_cours** : au moins un "Enregistrer" effectué (brouillon).
- **soumis** : l'agent a cliqué "Soumettre" ; en attente du chef.
- **validé** : le chef a validé → `official_progress_percent` figé.
- **rejeté / à corriger** : le chef a rejeté avec motif → retour `en_cours`.

### Action composée

L'action parente n'est **pas suivie directement**. Sa performance est calculée depuis
ses sous-actions. Elle passe **automatiquement** à "terminé" quand **TOUTES** ses
sous-actions sont validées par le chef (décision validée).

---

## 5. Conditions Save vs Submit

| Champ          | Save (brouillon) | Submit (soumission)                          |
| -------------- | ---------------- | -------------------------------------------- |
| Quantité       | optionnel        | **requis** si `quantitative`                 |
| Justificatif   | optionnel        | **requis** toujours (sauf si déjà déposé)    |
| Commentaire    | optionnel        | **requis** si `requires_comment`             |
| Difficulté     | optionnel        | **requis** si `allows_difficulty` + signalée |

`Enregistrer` ne contraint jamais rien (brouillon libre, saisie progressive possible).
`Soumettre` applique la validation complète.

---

## 6. Calcul de performance par type

### 6.1 Quantitative — seuil numérique

```
taux = (quantité réalisée / quantité cible) × 100
```

Mappé sur les paliers `seuil_t1..t4` :

|        Taux | Statut performance |
| ----------: | ------------------ |
|         0 % | non_demarre        |
|  1 % → t1   | critique           |
| t1 → t2     | en_alerte          |
| t2 → t3     | acceptable         |
| t3 → 100 %  | satisfaisante      |
|     ≥ 100 % | cible_atteinte     |
|     > 100 % | cible_depassee     |

### 6.2 Non quantitative — seuil binaire

| Situation                  | Performance      |
| -------------------------- | ---------------- |
| Pièce absente              | 0 %              |
| Pièce téléversée           | 100 % provisoire |
| Pièce validée par le chef  | 100 % officiel   |

### 6.3 Composée — seuil pondéré

```
performance = Σ (performance_sous_action × poids_sous_action)
```

Contrainte (décision validée) : **Σ poids = 100 %** obligatoire à l'enregistrement PTA.
Le formulaire refuse de sauvegarder si la somme des poids ≠ 100 %.

**Sous-actions simplifiées (v1)** : une sous-action quantitative utilise `réalisé/prévu`
(0-100%), une non-quantitative `0/100%`. Pas de paliers t1-t4 par sous-action en v1.

---

## 7. Modèle de données

### 7.1 Table `actions`

**Déjà présent (réutilisé) :**
`mode_evaluation`, `type_cible`, `quantite_cible`, `unite_cible`, `methode_calcul`,
`seuil_mode`, `seuil_t1..t4`, `seuil_minimum`, `justificatif_obligatoire`,
`date_echeance`, `echeance_cible`, `responsable_id`, `criteres_validation`,
`quantite_realisee`, `progression_reelle`, `progression_theorique`, `taux_atteinte_cible`,
`statut`, `statut_dynamique`, `statut_validation`, `statut_parametrage`,
`statut_performance`, `motif_validation_chef`.

**À créer (P1) :**

| Colonne                     | Type      | Rôle                                       |
| --------------------------- | --------- | ------------------------------------------ |
| `type_action`               | varchar   | quantitative / non_quantitative / composee |
| `requires_comment`          | bool      | commentaire obligatoire à la soumission    |
| `allows_difficulty`         | bool      | champ difficulté activé                    |
| `official_progress_percent` | numeric   | performance officielle (figée à validation)|

### 7.2 Table `sous_actions`

**Déjà présent (réutilisé) :**
`cible_prevue`, `unite`, `quantite_realisee`, `taux_realisation`, `taux_execution`,
`statut`, `est_effectuee`, `date_debut`, `date_fin`, `date_realisation`, `completed_at`,
`resultat_attendu`, `resultat_obtenu`, `commentaire`.

**À créer (P1) :**

| Colonne                     | Type      | Rôle                                  |
| --------------------------- | --------- | ------------------------------------- |
| `sub_action_type`           | varchar   | quantitative / non_quantitative       |
| `weight`                    | numeric   | poids (%) pour calcul pondéré         |
| `requires_proof`            | bool      | justificatif obligatoire              |
| `requires_comment`          | bool      | commentaire obligatoire               |
| `allows_difficulty`         | bool      | champ difficulté activé               |
| `official_progress_percent` | numeric   | performance officielle                |
| `validation_status`         | varchar   | non_soumise / soumise / validee / rejetee |

---

## 8. Workflow final

```
PTA
 ↓ création de l'action
 ↓ choix type_action (quantitative | non_quantitative | composee)
 ↓ définition cible + unité + seuils (si quantitative)
 ↓ définition échéance
 ↓ définition conditions (justificatif / commentaire / difficulté)
 ↓ affectation responsable
 ↓ [si composée] définition des sous-actions (type, cible, poids Σ=100%)
 ↓
Suivi par l'agent
 ↓ enregistrement de l'avancement (Save — aucune contrainte)
 ↓ calcul performance PROVISOIRE selon cible + seuil PTA
 ↓ soumission (Submit — conditions PTA vérifiées)
 ↓
Validation chef
 ↓ valider → performance OFFICIELLE figée
 ↓ rejeter → motif obligatoire → retour en_cours
 ↓
[composée] bascule auto à "terminé" quand toutes sous-actions validées
 ↓
Reporting (sur official_progress_percent uniquement)
```

---

## 9. Plan d'implémentation (8 phases)

| Phase | Contenu                                                       |
| ----- | ------------------------------------------------------------- |
| P1    | Migration BDD (colonnes manquantes + mapping auto 66 actions) |
| P2    | Modèles + `ActionPerformanceService` (calcul type + transverses) |
| P3    | Formulaire PTA (définition cible/seuil/unité/conditions)      |
| P4    | Suivi agent (enregistrement + performance provisoire)         |
| P5    | Soumission + validation chef (officialisation)                |
| P6    | Action composée (calcul pondéré + bascule auto)               |
| P7    | Reporting (bascule sur `official_progress_percent`)           |
| P8    | Tests E2E complets + recette                                  |

---

## 10. Décisions actées (2026-05-31)

1. **Seuils temporel + conformité = transverses** (1 seuil principal + 2 dimensions toujours évaluées).
2. **Performance provisoire ET officielle = 2 champs séparés** ; reporting sur officiel uniquement.
3. **Action composée = soumission auto** quand toutes les sous-actions sont validées.
4. **Poids des sous-actions : Σ = 100 % obligatoire** à l'enregistrement PTA.
5. **Un seul champ `type_action`** ; type de cible et de seuil dérivés automatiquement.
6. **Validateur = chef de service** (règle globale via WorkflowSettings, pas configurable par action en v1).
7. **Migration auto** des 66 actions existantes vers le nouveau modèle.
8. **Sous-actions simplifiées v1** : pas de paliers t1-t4 par sous-action (réalisé/prévu ou 0/100%).
