# Analyse des graphiques — Page « Graphiques » du dashboard

**Date :** 05/06/2026 · **Mise à jour :** 28/06/2026 · **Périmètre :** onglet `charts` (`_panel-charts.blade.php`) + reporting avancé · **Stack :** Chart.js + Plotly + D3 (gantt) + fallbacks SVG/CSS server-side · **Thème :** `chart-theme.js` / `dashboard-render.js`

---

## 1. Inventaire des graphiques présents

| # | Graphique | Technologie | Type |
|---|-----------|-------------|------|
| 1 | Hero — Score global pondéré | SVG + CSS (server-side) | Big number + barre de progression + seuil + sparkline |
| 2 | Jauges KPI (Délai, Performance) | Chart.js `doughnut` 180° + fallback CSS | Demi-jauges, cutout 78 %, plugin texte central |
| 3 | Évolution mensuelle des indicateurs | Chart.js `line`/area + fallback SVG | Courbe avec dégradé, toggle 3M/6M/12M/Tout |
| 4 | Répartition des statuts | CSS (barres horizontales) | Liste de barres % par statut |
| 5 | Performance par unité | Chart.js `bar` + fallback CSS | Barres verticales (KPI vs progression) |
| 6 | Actions classées (meilleur score) | CSS (barres classées + liens) | Classement cliquable |
| 7 | Courbes d'évolution trimestrielle | SVG `polyline` (server-side) | 2 séries (exécution / score) |
| 8 | Graphiques de décision (services/agents) | CSS (barres %) | Barres de progression |
| 9 | Reporting : Statuts par unité | Chart.js `bar` empilé | Barres empilées |
| 10 | Reporting : Avancement hebdo | Chart.js `line` area | Réel vs théorique (pointillé) |
| 11 | Reporting : Tendance KPI | Chart.js mixte `bar`+`line` | Valeur / Cible / Seuil |
| 12 | Reporting : Gantt + Gantt critique | D3 | Diagramme de Gantt |
| 13 | Moyenne agents | Plotly + fallback CSS | Jauge dynamique |
| 14 | Top 10 agents | Plotly + fallback CSS | Barres horizontales |
| 15 | Positionnement 3D agents | Plotly + fallback CSS | Nuage 3D charge / clôture / score |
| 16 | Heatmap agents | Plotly + fallback CSS | Lecture croisée performance / charge |

**État global :** la base est déjà de bon niveau — dégradés (`barGradient`, `chartGradient`), barres arrondies, tooltips « glass », animations `easeOutCubic` en cascade, dark mode, click-through vers les actions filtrées, fallbacks accessibles, et graphiques Plotly pour les analyses agents. On est à ~80 % d'un rendu « pro ». Les améliorations ci-dessous visent les 20 % restants.

## Mise à jour 2026-06-28

- Les **graphes de décision** de l'onglet Graphiques sont réaffichés via la vraie condition métier au lieu d'un garde temporaire `@if (false...)`.
- Les graphiques Plotly générés côté Python sont maintenant **cachés par contexte** (`tenant`, année, période, direction, service, rôle, version du script et payload).
- Le rendu JS conserve la figure Plotly dans le DOM (`__pasPlotlyFigure`) pour permettre un **aperçu interactif grand format**.
- Le modal d'aperçu sait ouvrir les graphiques **Plotly** et **Chart.js**, puis exporter l'aperçu en PNG.
- L'onglet dashboard est clarifié : `Synthèse`, `Graphiques`, `Analyse avancée`.
- La synthèse expose des filtres décisionnels partagés avec le Suivi PTA : période, statut de suivi, statut délai et alerte échéance.

---

## 2. Améliorations PRIORITAIRES (impact fort, effort faible)

### 2.1 ⚠️ Réactiver les value-labels et les lignes de seuil (plugins morts)
Les paquets `chartjs-plugin-datalabels` (^2.2.0) et `chartjs-plugin-annotation` (^3.1.0) sont **installés mais inactifs** :
- `barDataLabels()` → `return false;` (stub)
- `kpiAnnotations()` → `return {};` (stub)
- Aucun `import` / `Chart.register()` de ces plugins.

**Conséquence :** les barres n'affichent pas leurs valeurs, et les lignes Cible/Seuil sur la tendance KPI ne sont **pas tracées**. C'est le gain visuel le plus important et quasi gratuit.

**À faire :**
1. Importer + enregistrer les deux plugins dans `dashboard-charts-init.js`.
2. Implémenter `barDataLabels(fmt)` : labels en haut de barre, `font weight 800`, couleur `theme.text`, `clamp` pour ne pas sortir du cadre, masqués si valeur = 0.
3. Implémenter `kpiAnnotations()` : ligne horizontale pointillée au seuil 60 (label « Seuil qualité ») + zone colorée < 60 en rouge très léger.

### 2.2 Unifier « Répartition des statuts » en vrai doughnut
Aujourd'hui c'est une liste de barres CSS, alors qu'une **répartition** appelle un donut. Le composant doughnut existe déjà (jauges). Proposer un doughnut central avec total au centre (réutiliser le plugin `anbgCenterText`), légende à droite avec % et pastilles. Garde la liste CSS comme fallback no-JS.

### 2.3 Cohérence des rayons d'angle de barres
Les `borderRadius` varient (6, 8, 9, 10) selon les graphiques. Fixer **une seule valeur** (ex. 8) via les defaults Chart.js déjà présents dans `chart-theme.js`, et retirer les overrides locaux. Cohérence = perception « pro ».

### 2.4 Sparkline & SVG : passer en courbes lissées
Les `polyline` server-side (hero sparkline, courbes trimestrielles, fallback mensuel) sont en segments droits anguleux. Les convertir en `path` avec lissage Catmull-Rom/Bézier (`stroke-linejoin:round` déjà là mais insuffisant). Rendu nettement plus premium, surtout sur le hero.

---

## 3. Améliorations FINITION (le « ultra beau »)

### 3.1 Dégradés de barres verticaux → diagonaux + halo
`barGradient` est vertical opaque. Pour un effet plus riche : léger dégradé + `shadowColor`/`shadowBlur` discret sous la barre (via un mini-plugin `beforeDatasetDraw`) pour donner de la profondeur. Garder subtil (blur 8-10, alpha 0.15).

### 3.2 Jauges KPI — animation d'aiguille + graduations
Les demi-jauges doughnut sont propres mais « plates ». Ajouter : animation de remplissage au mount (déjà 600 ms — ok), petites graduations 0/50/100 sous l'arc, et une couleur d'arc en **dégradé** (vert→bleu→orange→rouge) plutôt qu'une couleur unie par palier — transition visuelle plus fluide.

### 3.3 Ligne « Évolution mensuelle » — point actif + crosshair
Au survol : afficher une ligne verticale (crosshair) + agrandir uniquement le point survolé (déjà `hoverRadius 7`) et estomper les autres séries (`hover` mode `index` déjà actif). Ajouter un **dernier point mis en évidence** (anneau pulsé) pour signaler la valeur courante.

### 3.4 Légendes — alignement et interactivité
Les légendes sont en bas, point-style circle (bien). Ajouter le **toggle on/off** au clic de légende avec transition douce (natif Chart.js mais vérifier qu'il n'est pas bloqué par le click-through). Sur mobile, passer la légende au-dessus pour gagner en hauteur de tracé.

### 3.5 Axes — formatage des valeurs
Les ticks Y vont jusqu'à 100 : suffixer « % » directement dans le `callback` du tick Y des graphiques de taux (actuellement seulement dans les tooltips). Réduit la charge cognitive.

### 3.6 États vides — illustrations plutôt que texte
`dashboard-chart-empty` affiche « Aucune donnée ». Remplacer par le composant `<x-ui.empty-state icon="chart">` déjà utilisé ailleurs (cohérence + plus beau).

### 3.7 Gantt (D3) — polish
Barres arrondies (rx 4), bande « aujourd'hui » verticale, couleur par statut alignée sur `colorForStatus`, et tooltip HTML identique au glass Chart.js (actuellement style D3 distinct → incohérence).

---

## 4. Accessibilité & robustesse (déjà bon, à compléter)

- ✅ Fallbacks SVG/CSS server-side, `aria-label` sur les SVG, `role="img"`.
- ➕ Ajouter une **table de données masquée** (`.sr-only`) sous chaque canvas Chart.js pour les lecteurs d'écran (Chart.js n'expose rien d'accessible nativement).
- ➕ Respecter `prefers-reduced-motion` dans les configs Chart.js (`animation: false` si la media query est active) — actuellement géré en CSS mais pas côté JS.
- ➕ Vérifier le contraste des dégradés de barres clairs sur fond blanc (le bas à alpha 0.7 peut passer sous 3:1).

---

## 5. Plan d'action recommandé (ordre)

1. **Réactiver datalabels + annotations** (§2.1) — gain maximal.
2. **Doughnut statuts** (§2.2) + **rayons unifiés** (§2.3).
3. **Lissage des courbes SVG** (§2.4) + **suffixe % axes** (§3.5).
4. **Finitions** : crosshair, halo barres, jauges graduées, états vides illustrés (§3).
5. **A11y JS** : table sr-only + reduced-motion (§4).
6. **Gantt** polish (§3.7).

> Les §1→3 transforment déjà le rendu en « ultra beau » ; §4→6 sont la couche d'excellence.
