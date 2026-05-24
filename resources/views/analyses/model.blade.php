{{-- =================================================================
     resources/views/analyses/model.blade.php
     Analyse canonique du modèle PAS — style IDE/dark
     Usage :
       Route::get('/analyses/model', fn () => view('analyses.model'));
     Dépendances :
       - public/css/analyse-model.css  (joint à ce kit)
       - polices Google : JetBrains Mono + Inter (importées par le CSS)
     ================================================================= --}}
@extends('layouts.admin')

@section('title', 'Analyse — état du modèle')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/analyse-model.css') }}">
@endpush

@section('content')

<div class="app">

    <!-- ── TITLEBAR ──────────────────────────────────────────── -->
    <header class="titlebar">
        <div class="traffic"><span></span><span></span><span></span></div>
        <div class="center"><b>repo://carlngomayilla/PAS</b> · analyse · <span style="color:var(--c-yellow)">model.md</span></div>
        <div class="right"><span class="dot"></span>HEAD · 5db34887 · main</div>
    </header>

    <!-- ── TABBAR ────────────────────────────────────────────── -->
    <nav class="tabbar">
        <div class="tab"><span class="ico md">md</span>README.md<span class="x">×</span></div>
        <div class="tab"><span class="ico php">PHP</span>schema.php<span class="x">×</span></div>
        <div class="tab active"><span class="ico md">md</span>model.md<span class="x">×</span></div>
        <div class="tab"><span class="ico php">PHP</span>relink_paos.php<span class="x">×</span></div>
    </nav>

    <!-- ── WORKSPACE ─────────────────────────────────────────── -->
    <div class="workspace">

        <!-- ─────────── SIDEBAR ─────────── -->
        <aside class="sidebar">
            <div class="head">EXPLORER<span class="badge">PAS</span></div>

            <div class="group">
                <div class="title">analyses</div>
                <div class="item"><span class="ico md">md</span><span class="name">analyse-depot-pas<span class="ext">.html</span></span><span class="meta">v1</span></div>
                <div class="item"><span class="ico md">md</span><span class="name">…v2<span class="ext">.html</span></span><span class="meta">v2</span></div>
                <div class="item"><span class="ico md">md</span><span class="name">…v3<span class="ext">.html</span></span><span class="meta">★</span></div>
                <div class="item"><span class="ico md">md</span><span class="name">…v4<span class="ext">.html</span></span><span class="meta">v4</span></div>
                <div class="item active"><span class="ico md">md</span><span class="name">mars-2026<span class="ext">.md</span></span><span class="meta flag">NEW</span></div>
            </div>

            <div class="group">
                <div class="title">migrations · 13 mars</div>
                <div class="item"><span class="ico php">⚡</span><span class="name">09:00 relink_paos_to_pas_objectifs</span><span class="meta flag">★</span></div>
                <div class="item"><span class="ico php">⚡</span><span class="name">13:00 add_service_scope_to_paos</span><span class="meta flag">★</span></div>
                <div class="item"><span class="ico php">⚡</span><span class="name">13:01 normalize_ptas_unique_per_pao</span><span class="meta flag">★</span></div>
                <div class="item"><span class="ico php">⚡</span><span class="name">15:00 align_pas_structure_metadata</span><span class="meta">gov</span></div>
            </div>

            <div class="group">
                <div class="title">migrations · 23-31 mars</div>
                <div class="item"><span class="ico php">⚡</span><span class="name">23.03 attachment_metadata</span><span class="meta">msg</span></div>
                <div class="item"><span class="ico php">⚡</span><span class="name">24.03 quality_and_risk_kpis</span><span class="meta flag-red">KPI</span></div>
                <div class="item"><span class="ico php">⚡</span><span class="name">30.03 add_is_active_to_users</span><span class="meta">usr</span></div>
                <div class="item"><span class="ico php">⚡</span><span class="name">31.03 est_a_renseigner</span><span class="meta">kpi</span></div>
            </div>

            <div class="group">
                <div class="title">schema · tables touchées</div>
                <div class="item"><span class="ico sql">▦</span><span class="name">pas</span><span class="meta">+2</span></div>
                <div class="item"><span class="ico sql">▦</span><span class="name">pas_axes</span><span class="meta">+4</span></div>
                <div class="item"><span class="ico sql">▦</span><span class="name">pas_objectifs</span><span class="meta">+3</span></div>
                <div class="item"><span class="ico sql">▦</span><span class="name">paos</span><span class="meta flag">+2 *</span></div>
                <div class="item"><span class="ico sql">▦</span><span class="name">ptas</span><span class="meta flag">1:1</span></div>
                <div class="item"><span class="ico sql">▦</span><span class="name">action_kpis</span><span class="meta">+2</span></div>
                <div class="item"><span class="ico sql">▦</span><span class="name">kpis</span><span class="meta">+1</span></div>
                <div class="item"><span class="ico sql">▦</span><span class="name">messages</span><span class="meta">+4</span></div>
                <div class="item"><span class="ico sql">▦</span><span class="name">users</span><span class="meta">+1</span></div>
            </div>
        </aside>

        <!-- ─────────── MAIN ─────────── -->
        <main class="main">
            <div class="main-inner">

                <!-- ─── DOC HEADER ─── -->
                <header class="doc-head">
                    <div class="breadcrumb">
                        <span class="seg">analyses</span>
                        <span class="sep">/</span>
                        <span class="seg">2026</span>
                        <span class="sep">/</span>
                        <span class="cur">model.md</span>
                        <span class="sep" style="margin-left:auto;color:var(--tx-4)">↗ read · 14 min</span>
                    </div>
                    <div class="tags">
                        <span class="tag major">★ Canonical</span>
                        <span class="tag">État du modèle · 20.05.2026</span>
                        <span class="tag">HEAD 5db34887</span>
                        <span class="tag">+8 migrations depuis v3</span>
                    </div>
                    <h1>Le modèle de pilotage, dans sa forme du <span class="hl">13 mars</span>.</h1>
                    <p class="lede">Lecture canonique de la chaîne <code class="inline" style="font-size:12.5px;background:var(--bg-elevated);">PAS → PAO → PTA → Action</code> après les huit migrations versées au dépôt entre le 13 et le 31 mars 2026. Quatre d'entre elles, jouées le même jour (09:00 → 15:00), ont refondu la forme de l'arbre. Ce document est la référence à partir de laquelle les maquettes doivent être relues.</p>
                </header>

                <!-- ─── STAT STRIP ─── -->
                <div class="stat-strip">
                    <div class="cell">
                        <div class="lbl">Migrations</div>
                        <div class="num">8<small>nouv.</small></div>
                        <div class="delta">+8 depuis v3</div>
                    </div>
                    <div class="cell">
                        <div class="lbl">Structurelles</div>
                        <div class="num">4</div>
                        <div class="delta warn">toutes le 13.03</div>
                    </div>
                    <div class="cell">
                        <div class="lbl">Tables touchées</div>
                        <div class="num">9</div>
                        <div class="delta">PAS hiérarchie + KPI</div>
                    </div>
                    <div class="cell">
                        <div class="lbl">Cardinalité PAO↔PTA</div>
                        <div class="num">1:1</div>
                        <div class="delta neg">était 1:N</div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════ -->
                <!-- § 01 — ERRATA -->
                <!-- ═══════════════════════════════════════ -->
                <section class="section" id="s01">
                    <div class="section-head">
                        <span class="num">§ 01</span>
                        <h2>Errata sur v3 — ce que nous avions mal compris</h2>
                    </div>
                    <p class="dek">v3 décrivait la chaîne PAS → PAO → PTA → Action comme un arbre où le PAO portait le niveau "direction" et le PTA portait le niveau "service". Ce n'est plus vrai depuis le 13 mars.</p>

                    <div class="callout">
                        <div class="icon">!</div>
                        <div class="body">
                            <div class="head">Correction · forme du modèle</div>
                            Le PAO n'est plus l'enfant direct du PAS. Il est désormais l'enfant d'un <b>objectif spécifique du PAS</b> (<code>pas_objectifs</code>), et il porte à la fois <b>la direction et le service</b>. Le PTA, lui, n'a plus qu'un seul PAO parent — <b>relation 1:1</b>. Le service est donc remonté du niveau PTA au niveau PAO.
                        </div>
                    </div>

                    <div class="ba-grid">
                        <div class="ba-card before">
                            <div class="h"><span class="dot"></span>avant · v3 / pré-13.03</div>
                            <pre class="ba-tree"><span class="root">PAS</span>
├─ axe
│  └─ objectif        <span class="meta">// décoratif</span>
└─ <span class="node">PAO</span>             <span class="meta">// (direction)</span>
   └─ <span class="node">PTA</span> × N      <span class="meta">// (service) une par service</span>
      └─ Action</pre>
                            <p style="margin: 14px 0 0; font-size: 0.85rem; color: var(--tx-3); line-height: 1.5;">L'objectif PAS et le PAO vivaient en parallèle. Un PAO pouvait avoir plusieurs PTA, un par service.</p>
                        </div>
                        <div class="ba-card after">
                            <div class="h"><span class="dot"></span>après · post-13.03</div>
                            <pre class="ba-tree"><span class="root">PAS</span>
├─ axe <span class="new">+ période</span>
│  └─ <span class="node">objectif</span> <span class="new">+ valeurs_cible (JSON)</span>
│     └─ <span class="node">PAO</span> <span class="new">(direction + service)</span>
│        └─ <span class="node">PTA</span> <span class="new">(1:1)</span>
│           └─ Action</pre>
                            <p style="margin: 14px 0 0; font-size: 0.85rem; color: var(--tx-3); line-height: 1.5;">L'objectif devient un nœud structurant. Le PAO est éclaté en autant d'exemplaires que de services. Le PTA est une projection 1:1 du PAO sur l'exécution.</p>
                        </div>
                    </div>
                </section>

                <!-- ═══════════════════════════════════════ -->
                <!-- § 02 — LE 13 MARS -->
                <!-- ═══════════════════════════════════════ -->
                <section class="section" id="s02">
                    <div class="section-head">
                        <span class="num">§ 02</span>
                        <h2>Le 13 mars, en quatre gestes</h2>
                    </div>
                    <p class="dek">Lecture chronologique des quatre migrations jouées le 13 mars 2026 — du matin (09h00) à l'après-midi (15h00). En six heures, le modèle de pilotage a été refondé.</p>

                    <div class="migrations">
                        <div class="row head">
                            <div>heure</div>
                            <div>migration · effet</div>
                            <div>type</div>
                            <div style="text-align:right">impact</div>
                        </div>
                        <div class="row major">
                            <div class="time"><span class="day">09:00</span>13.03.26</div>
                            <div class="file">relink_paos_to_pas_objectifs<span class="ext">.php</span>
                                <span class="desc">Le PAO cesse d'être l'enfant direct du PAS. Ajout de <code class="inline" style="font-size:11px;">paos.pas_objectif_id</code> (FK nullable vers <code class="inline" style="font-size:11px;">pas_objectifs</code>) + backfill rétroactif rattachant chaque PAO au premier objectif disponible (priorité à la direction qui correspond). L'unicité passe de <b>(pas, annee, direction)</b> à <b>(pas_objectif, annee, direction)</b>.</span>
                            </div>
                            <div class="tag"><span class="t struct">structurel</span></div>
                            <div class="impact"><div class="v">+1 niv.</div></div>
                        </div>
                        <div class="row major">
                            <div class="time"><span class="day">13:00</span>13.03.26</div>
                            <div class="file">add_service_scope_to_paos_table<span class="ext">.php</span>
                                <span class="desc">Le PAO gagne <code class="inline" style="font-size:11px;">service_id</code>. Surtout&nbsp;: <b>les PAO existants sont éclatés</b> — un par service rattaché via les PTA. Le backfill duplique le PAO, déplace les PTA correspondants vers le nouvel ID, puis pose l'unicité sur <b>(objectif, annee, direction, service)</b>. Fallback : premier service de la direction si aucun PTA.</span>
                            </div>
                            <div class="tag"><span class="t struct">structurel</span></div>
                            <div class="impact"><div class="v">×N PAO</div></div>
                        </div>
                        <div class="row major">
                            <div class="time"><span class="day">13:01</span>13.03.26</div>
                            <div class="file">normalize_ptas_unique_per_pao<span class="ext">.php</span>
                                <span class="desc">Conséquence immédiate de la migration de 13h00. L'unicité PTA passe de <b>(pao_id, service_id)</b> à <b>(pao_id)</b> seul. <b>Un PAO ne peut désormais plus avoir qu'un seul PTA.</b> PAO et PTA deviennent structurellement deux facettes d'une même unité opérationnelle (planification ↔ exécution).</span>
                            </div>
                            <div class="tag"><span class="t struct">structurel</span></div>
                            <div class="impact"><div class="v">1:1</div></div>
                        </div>
                        <div class="row">
                            <div class="time"><span class="day">15:00</span>13.03.26</div>
                            <div class="file">align_pas_structure_metadata<span class="ext">.php</span>
                                <span class="desc">Pose le squelette de gouvernance sur tout l'arbre stratégique. <code class="inline" style="font-size:11px;">created_by</code> + <b>soft deletes</b> sur <code class="inline" style="font-size:11px;">pas</code>, <code class="inline" style="font-size:11px;">pas_axes</code>, <code class="inline" style="font-size:11px;">pas_objectifs</code>. Les axes gagnent leur propre <code class="inline" style="font-size:11px;">periode_debut/fin</code>. L'objectif gagne <code class="inline" style="font-size:11px;">valeurs_cible</code> (JSON) — la cible n'est plus scalaire mais structurée.</span>
                            </div>
                            <div class="tag"><span class="t gov">gouvernance</span></div>
                            <div class="impact"><div class="v">+3 tbl</div></div>
                        </div>
                    </div>

                    <h3>Extrait clé · la duplication des PAO</h3>
                    <p>Le backfill de 13h00 est ce qui rend la migration irréversible en pratique — il <b>multiplie physiquement</b> les enregistrements. Voici l'extrait critique :</p>

                    <div class="codeblock">
                        <div class="cb-head">
                            <span class="lang">PHP</span>
                            <span class="path">2026_03_13_130000_add_service_scope_to_paos_table.php</span>
                            <span class="copy">L 78–101</span>
                        </div>
<pre><span class="line"><span class="ln">78</span><span class="tok-com">// pour chaque PAO existant, on collecte les services via les PTA…</span></span><span class="line"><span class="ln">79</span><span class="tok-var">$serviceIds</span> = DB::<span class="tok-fn">table</span>(<span class="tok-str">'ptas'</span>)</span><span class="line"><span class="ln">80</span>    -><span class="tok-fn">where</span>(<span class="tok-str">'pao_id'</span>, (int) <span class="tok-var">$pao</span>->id)</span><span class="line"><span class="ln">81</span>    -><span class="tok-fn">pluck</span>(<span class="tok-str">'service_id'</span>)</span><span class="line"><span class="ln">82</span>    -><span class="tok-fn">unique</span>()-><span class="tok-fn">values</span>()-><span class="tok-fn">all</span>();</span><span class="line"><span class="ln">83</span></span><span class="line add"><span class="marker">+</span><span class="ln">84</span><span class="tok-var">$primaryServiceId</span> = <span class="tok-fn">array_shift</span>(<span class="tok-var">$serviceIds</span>);  <span class="tok-com">// 1er service</span></span><span class="line add"><span class="marker">+</span><span class="ln">85</span>DB::<span class="tok-fn">table</span>(<span class="tok-str">'paos'</span>)-><span class="tok-fn">where</span>(<span class="tok-str">'id'</span>, <span class="tok-var">$pao</span>->id)</span><span class="line add"><span class="marker">+</span><span class="ln">86</span>    -><span class="tok-fn">update</span>([<span class="tok-str">'service_id'</span> => <span class="tok-var">$primaryServiceId</span>]);</span><span class="line"><span class="ln">87</span></span><span class="line"><span class="ln">88</span><span class="tok-kw">foreach</span> (<span class="tok-var">$serviceIds</span> <span class="tok-kw">as</span> <span class="tok-var">$serviceId</span>) {</span><span class="line add"><span class="marker">+</span><span class="ln">89</span>    <span class="tok-com">// …on duplique le PAO pour les services suivants</span></span><span class="line add"><span class="marker">+</span><span class="ln">90</span>    <span class="tok-var">$newPaoId</span> = DB::<span class="tok-fn">table</span>(<span class="tok-str">'paos'</span>)-><span class="tok-fn">insertGetId</span>([</span><span class="line add"><span class="marker">+</span><span class="ln">91</span>        <span class="tok-str">'pas_id'</span>          => <span class="tok-var">$pao</span>->pas_id,</span><span class="line add"><span class="marker">+</span><span class="ln">92</span>        <span class="tok-str">'pas_objectif_id'</span> => <span class="tok-var">$pao</span>->pas_objectif_id,</span><span class="line add"><span class="marker">+</span><span class="ln">93</span>        <span class="tok-str">'direction_id'</span>    => <span class="tok-var">$pao</span>->direction_id,</span><span class="line add"><span class="marker">+</span><span class="ln">94</span>        <span class="tok-str">'service_id'</span>      => <span class="tok-var">$serviceId</span>,</span><span class="line add"><span class="marker">+</span><span class="ln">95</span>        <span class="tok-str">'annee'</span>           => <span class="tok-var">$pao</span>->annee,</span><span class="line add"><span class="marker">+</span><span class="ln">96</span>        <span class="tok-str">'titre'</span>           => <span class="tok-var">$pao</span>->titre,</span><span class="line add"><span class="marker">+</span><span class="ln">97</span>        <span class="tok-com">// …copie complète des champs</span></span><span class="line add"><span class="marker">+</span><span class="ln">98</span>    ]);</span><span class="line add"><span class="marker">+</span><span class="ln">99</span>    <span class="tok-com">// puis on rattache les PTA correspondants au nouveau PAO</span></span><span class="line add"><span class="marker">+</span><span class="ln">100</span>    DB::<span class="tok-fn">table</span>(<span class="tok-str">'ptas'</span>)-><span class="tok-fn">where</span>(<span class="tok-str">'service_id'</span>, <span class="tok-var">$serviceId</span>)</span><span class="line add"><span class="marker">+</span><span class="ln">101</span>        -><span class="tok-fn">update</span>([<span class="tok-str">'pao_id'</span> => <span class="tok-var">$newPaoId</span>]);</span><span class="line"><span class="ln">102</span>}</span></pre>
                    </div>
                </section>

                <!-- ═══════════════════════════════════════ -->
                <!-- § 03 — KPI -->
                <!-- ═══════════════════════════════════════ -->
                <section class="section" id="s03">
                    <div class="section-head">
                        <span class="num">§ 03</span>
                        <h2>Moteur KPI — de 3 à 5 axes</h2>
                    </div>
                    <p class="dek">Migration du 24 mars : la table <code>action_kpis</code> gagne <code>kpi_qualite</code> et <code>kpi_risque</code>. Le KPI global se calcule désormais sur cinq dimensions, plus le composite.</p>

                    <div class="radar-block">
                        <svg viewBox="0 0 240 240" xmlns="http://www.w3.org/2000/svg" aria-label="KPI cinq axes">
                            <g fill="none" stroke="#262e58" stroke-width="1">
                                <polygon points="120,30 209,87 175,189 65,189 31,87"/>
                                <polygon points="120,60 188,98 162,174 78,174 52,98" opacity=".7"/>
                                <polygon points="120,90 167,109 149,159 91,159 73,109" opacity=".5"/>
                            </g>
                            <g stroke="#262e58" stroke-width="1">
                                <line x1="120" y1="120" x2="120" y2="30"/>
                                <line x1="120" y1="120" x2="209" y2="87"/>
                                <line x1="120" y1="120" x2="175" y2="189"/>
                                <line x1="120" y1="120" x2="65" y2="189"/>
                                <line x1="120" y1="120" x2="31" y2="87"/>
                            </g>
                            <polygon points="120,52 195,93 165,180 81,180" fill="#5fb4ff" fill-opacity=".18" stroke="#5fb4ff" stroke-width="2"/>
                            <polygon points="120,52 195,93 165,180 81,180 44,94" fill="#f9b13c" fill-opacity=".28" stroke="#f9b13c" stroke-width="2" stroke-dasharray="4 3"/>
                            <g font-family="JetBrains Mono, monospace" font-size="10" font-weight="700" fill="#b5bcd9">
                                <text x="120" y="22" text-anchor="middle">DÉLAI</text>
                                <text x="218" y="89" text-anchor="start">PERFORMANCE</text>
                                <text x="180" y="204" text-anchor="middle">CONFORMITÉ</text>
                                <text x="60" y="204" text-anchor="middle" fill="#f9b13c">QUALITÉ</text>
                                <text x="22" y="89" text-anchor="end" fill="#f9b13c">RISQUE</text>
                            </g>
                            <circle cx="120" cy="120" r="22" fill="#11162e" stroke="#f9b13c" stroke-width="1.5"/>
                            <text x="120" y="118" text-anchor="middle" font-family="Inter, system-ui" font-size="9" font-weight="800" fill="#e6e9f5">GLOBAL</text>
                            <text x="120" y="130" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="8" fill="#f9b13c">composite</text>
                        </svg>
                        <div class="radar-list">
                            <div class="r">
                                <span class="dot" style="background:#5fb4ff;"></span>
                                <span class="n">Délai</span>
                                <span class="field">kpi_delai</span>
                                <span></span>
                            </div>
                            <div class="r">
                                <span class="dot" style="background:#5fb4ff;"></span>
                                <span class="n">Performance</span>
                                <span class="field">kpi_performance</span>
                                <span></span>
                            </div>
                            <div class="r">
                                <span class="dot" style="background:#5fb4ff;"></span>
                                <span class="n">Conformité</span>
                                <span class="field">kpi_conformite</span>
                                <span></span>
                            </div>
                            <div class="r">
                                <span class="dot" style="background:#f9b13c;"></span>
                                <span class="n">Qualité</span>
                                <span class="field">kpi_qualite</span>
                                <span class="new">NEW</span>
                            </div>
                            <div class="r">
                                <span class="dot" style="background:#f9b13c;"></span>
                                <span class="n">Risque</span>
                                <span class="field">kpi_risque</span>
                                <span class="new">NEW</span>
                            </div>
                            <div class="r">
                                <span class="dot" style="background:#e6e9f5;"></span>
                                <span class="n">Global</span>
                                <span class="field">kpi_global · composite</span>
                                <span></span>
                            </div>
                        </div>
                    </div>

                    <p>Côté table <code>kpis</code> (la <i>bibliothèque</i> d'indicateurs), une autre migration du 31 mars ajoute <code>est_a_renseigner</code> : un drapeau qui distingue les indicateurs <b>saisis</b> par les agents de ceux qui sont <b>calculés</b> automatiquement par le moteur. Cette distinction est invisible des maquettes actuelles mais conditionne toute la conception des écrans de saisie — <b>deux modes très différents</b>.</p>
                </section>

                <!-- ═══════════════════════════════════════ -->
                <!-- § 04 — RESTE -->
                <!-- ═══════════════════════════════════════ -->
                <section class="section" id="s04">
                    <div class="section-head">
                        <span class="num">§ 04</span>
                        <h2>Le reste du lot</h2>
                    </div>
                    <p class="dek">Trois migrations plus tactiques. Elles confirment des intuitions de v3 et ouvrent des portes UX qui n'avaient pas été identifiées.</p>

                    <div class="impact-grid">
                        <div class="impact">
                            <div class="h">23.03 · messages</div>
                            <div class="t">Attachements first-class</div>
                            <div class="d"><code>attachment_original_name</code>, <code>attachment_mime_type</code>, <code>attachment_size_bytes</code>, <code>attachment_is_encrypted</code>. La messagerie quitte le stade preview — noms de fichiers, icônes par type, poids et badge "chiffré".</div>
                        </div>
                        <div class="impact">
                            <div class="h">30.03 · users</div>
                            <div class="t">Désactivation, pas suppression</div>
                            <div class="d"><code>is_active</code> avec backfill <code>true</code>. Combiné aux soft deletes : un agent peut être <b>archivé sans perdre son historique de saisie</b>. À refléter dans les sélecteurs de responsable.</div>
                        </div>
                        <div class="impact warn">
                            <div class="h">31.03 · kpis</div>
                            <div class="t">Saisi vs calculé</div>
                            <div class="d"><code>est_a_renseigner = true</code> par défaut. Drapeau qui pilote tout l'écran de saisie hebdomadaire : champ éditable ou cellule en lecture seule (auto-calculée).</div>
                        </div>
                        <div class="impact ok">
                            <div class="h">13.03 · pas</div>
                            <div class="t">Périodes d'axe & cibles JSON</div>
                            <div class="d"><code>pas_axes.periode_debut/fin</code> — un PAS pluri-annuel peut porter des axes à horizons distincts. <code>valeurs_cible</code> (JSON) libère la cible d'objectif : structurée, multiple, typée.</div>
                        </div>
                    </div>
                </section>

                <!-- ═══════════════════════════════════════ -->
                <!-- § 05 — TODO UX -->
                <!-- ═══════════════════════════════════════ -->
                <section class="section" id="s05">
                    <div class="section-head">
                        <span class="num">§ 05</span>
                        <h2>Conséquences UX — ce qu'il faut corriger</h2>
                    </div>
                    <p class="dek">Quatre ajustements à porter en priorité dans les écrans existants et dans la proposition d'Inbox de pilotage.</p>

                    <ul class="todo">
                        <li><div><b>Formulaire PAO — n'est plus un formulaire de direction.</b> Demander (a) à quel objectif spécifique du PAS le PAO contribue (sélecteur axe → objectif), (b) direction <b>et service</b> portants, (c) l'année. Le champ "PAS" devient un breadcrumb, pas un input.</div></li>
                        <li><div><b>Formulaire PTA — à fusionner avec le PAO.</b> Si la cardinalité PAO ↔ PTA est 1:1, créer un PTA séparé est une étape administrative sans valeur métier. Fusionner les deux écrans en "Plan de service" unifié.</div></li>
                        <li><div><b>Cartes & radars KPI — 5 axes, pas 3.</b> Tous les composants visuels passent de triangulaire à pentagonal (radar, jauges, exports). Garder un mode compact à 3 axes pour les listings denses.</div></li>
                        <li><div><b>Inbox de pilotage — corriger les libellés.</b> Remplacer "validation PTA" par "validation Plan opérationnel". Le mot PTA peut disparaître de l'interface utilisateur, au profit d'un terme plus métier.</div></li>
                        <li><div><b>Saisie hebdomadaire — deux modes.</b> Implémenter le drapeau <code>est_a_renseigner</code> : cellules éditables vs lecture seule (auto-calculées). Sans cette distinction, l'écran de saisie produit des incohérences.</div></li>
                    </ul>
                </section>

                <div style="margin-top: 56px; padding: 20px 0 0; border-top: 1px solid var(--bd); display: flex; justify-content: space-between; align-items: center; font-family: var(--mono); font-size: 11px; color: var(--tx-3);">
                    <span>— fin de <b style="color:var(--tx-2);">model.md</b> · référence canonique</span>
                    <span>HEAD <span style="color:var(--c-yellow);">5db34887</span> · git log --since="2026-03-13"</span>
                </div>

            </div>
        </main>

        <!-- ─────────── OUTLINE ─────────── -->
        <aside class="outline">
            <div class="head">Outline</div>
            <a href="#s01" class="o-item active"><span class="num">§01</span>Errata sur v3</a>
            <a href="#s02" class="o-item"><span class="num">§02</span>Le 13 mars en 4 gestes</a>
            <a href="#s02" class="o-item sub">09:00 relink objectifs</a>
            <a href="#s02" class="o-item sub">13:00 service scope</a>
            <a href="#s02" class="o-item sub">13:01 PTA 1:1</a>
            <a href="#s02" class="o-item sub">15:00 metadata</a>
            <a href="#s03" class="o-item"><span class="num">§03</span>KPI 5 axes</a>
            <a href="#s04" class="o-item"><span class="num">§04</span>Reste du lot</a>
            <a href="#s05" class="o-item"><span class="num">§05</span>Conséquences UX</a>

            <div class="legend">
                <div class="l-head">Densité du doc</div>
                <div class="minimap" title="Densité des sections">
                    <div class="mm yel" style="width: 76%"></div>
                    <div class="mm yel" style="width: 96%"></div>
                    <div class="mm blu" style="width: 70%"></div>
                    <div class="mm blu" style="width: 62%"></div>
                    <div class="mm grn" style="width: 58%"></div>
                </div>
            </div>

            <div class="legend">
                <div class="l-head">Légende</div>
                <div class="l-row"><span class="sw" style="background: rgba(249,177,60,.5); border:1px solid var(--c-yellow);"></span>structurel</div>
                <div class="l-row"><span class="sw" style="background: rgba(95,180,255,.5); border:1px solid var(--c-blue);"></span>gouvernance</div>
                <div class="l-row"><span class="sw" style="background: rgba(156,214,106,.5); border:1px solid var(--c-green);"></span>fonctionnel</div>
                <div class="l-row"><span class="sw" style="background: rgba(239,111,90,.5); border:1px solid var(--c-red);"></span>cassure</div>
            </div>

            <div class="legend">
                <div class="l-head">Versions antérieures</div>
                <a href="analyse-depot-pas.html" class="o-item" style="font-size:10.5px;">v1 · Rapport</a>
                <a href="analyse-depot-pas-v2.html" class="o-item" style="font-size:10.5px;">v2 · Action</a>
                <a href="analyse-depot-pas-v3.html" class="o-item" style="font-size:10.5px;">v3 · Graphe</a>
                <a href="analyse-depot-pas-v4.html" class="o-item" style="font-size:10.5px;">v4 · Éditorial</a>
                <a href="analyse-depot-pas-mars2026.html" class="o-item" style="font-size:10.5px;">mars2026 · Errata</a>
            </div>
        </aside>

    </div>

    <!-- ── STATUSBAR ─────────────────────────────────────────── -->
    <footer class="statusbar">
        <div class="sb-cell"><span class="branch">⎇ main</span></div>
        <div class="sb-cell"><span class="ahead">↑ 8</span><span style="color:var(--tx-4)">depuis v3</span></div>
        <div class="right-group">
            <div class="sb-cell"><span class="dot" style="background:var(--c-yellow); box-shadow: 0 0 6px var(--c-yellow);"></span><span class="warn">canonical · v5</span></div>
            <div class="sb-cell"><b>UTF-8</b></div>
            <div class="sb-cell"><b>Markdown</b></div>
            <div class="sb-cell">Ln 1, Col 1</div>
            <div class="sb-cell"><b>ANBG · PAS</b></div>
        </div>
    </footer>

</div>

<script>
    // Outline highlight on scroll
    (function() {
        const main = document.querySelector('.main');
        const sections = document.querySelectorAll('.section');
        const links = document.querySelectorAll('.outline .o-item:not(.sub)');
        function update() {
            const offset = 80;
            let active = sections[0];
            sections.forEach(s => {
                if (s.getBoundingClientRect().top < offset) active = s;
            });
            links.forEach(l => l.classList.remove('active'));
            const idx = [...sections].indexOf(active);
            const topLinks = document.querySelectorAll('.outline .o-item:not(.sub)');
            if (topLinks[idx]) topLinks[idx].classList.add('active');
        }
        main.addEventListener('scroll', update, {passive: true});
        // smooth anchor scroll inside .main
        document.querySelectorAll('.outline a[href^="#"]').forEach(a => {
            a.addEventListener('click', (e) => {
                const id = a.getAttribute('href').slice(1);
                const el = document.getElementById(id);
                if (el) { e.preventDefault(); main.scrollTo({top: el.offsetTop - 24, behavior: 'smooth'}); }
            });
        });
    })();
</script>

@endsection

@push('scripts')
{{-- Le script d'outline-sur-scroll est déjà inclus dans la vue ci-dessus.
     Si tu préfères l'isoler ici, déplace le bloc <script>…</script>
     de la fin de @section('content') vers ce push. --}}
@endpush
