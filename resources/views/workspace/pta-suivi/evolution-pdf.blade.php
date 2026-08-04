<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /* Couleurs reprises du support institutionnel ANBG :
           bleu #00B0F0 pour les bandeaux et l'en-tete des colonnes,
           filets gris #BFBFBF pour les lignes de libelle. */
        @page { size:A4 landscape; margin:8mm; }
        body { font-family:DejaVu Sans, sans-serif; color:#000; font-size:8px; margin:0; }
        .report-top { display:table; width:100%; border-collapse:collapse; margin-bottom:8px; }
        .report-logo, .report-title { display:table-cell; vertical-align:middle; border:1px solid #999; }
        .report-logo { width:20%; padding:5px; }
        .report-logo img { width:90px; }
        .report-title { width:80%; text-align:center; background:#00B0F0; color:#000; font-size:14px; font-weight:900; padding:6px; }
        .report-meta { margin:4px 0 10px; font-size:8px; font-weight:700; color:#1f2937; }

        .direction-header { margin:0 0 6px; border:1px solid #000; background:#00B0F0; padding:5px 7px; page-break-after:avoid; }
        .direction-header .label { font-size:7px; font-weight:900; letter-spacing:0.4px; }
        .direction-header .name { font-size:11px; font-weight:900; }
        .direction-header .owner { font-size:8px; font-weight:700; }

        .service-header { margin:0 0 6px; border:1px solid #000; border-left:4px solid #00B0F0; background:#e8f8fe; padding:4px 7px; page-break-after:avoid; }
        .service-header .label { font-size:7px; font-weight:900; color:#0b5f7a; letter-spacing:0.4px; }
        .service-header .name { font-size:10px; font-weight:900; color:#000; }
        .service-header .owner { font-size:8px; font-weight:700; color:#1f2937; }

        .service-wrap { margin-bottom:10px; }
        .oo-block { margin:0 0 8px; page-break-inside:avoid; }
        table.oo-table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:6.2px; }
        table.oo-table th, table.oo-table td { border:1px solid #000; padding:3px; vertical-align:top; overflow-wrap:break-word; }
        .band-key td { background:#ffffff; color:#000; font-weight:900; text-align:center; font-size:6.6px; letter-spacing:0.3px; border-color:#BFBFBF; }
        .band-value td { background:#00B0F0; color:#000; font-weight:900; text-align:center; font-size:7px; }
        table.oo-table tr.head th { background:#00B0F0; color:#000; font-weight:900; text-align:center; font-size:6.2px; }
        .col-description { width:18%; }
        .col-rmo { width:8%; }
        .col-cible { width:5%; }
        .col-debut { width:6%; }
        .col-fin { width:6%; }
        .col-etat { width:8%; }
        .col-ressources { width:14%; }
        .col-indicateurs { width:12%; }
        .col-taux { width:7%; }
        .col-risques { width:16%; }
        .cell-center { text-align:center; }
        .cell-rate { text-align:center; font-weight:900; }
        .empty-block { text-align:center; color:#666; font-style:italic; padding:6px; }
    </style>
</head>
<body>
    @php
        $logoPath = public_path('images/logo-wordmark.png');
        $logoData = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)) : null;
        $reportDirections = $directions ?? [];
    @endphp

    <div class="report-top">
        <div class="report-logo">
            @if ($logoData)<img src="{{ $logoData }}" alt="ANBG">@endif
        </div>
        <div class="report-title">{{ $title }}</div>
    </div>

    <div class="report-meta">
        {{ $scopeLabel }} — Éditée le {{ $generatedAt->format('d/m/Y à H:i') }}
    </div>

    @forelse ($reportDirections as $direction)
        <div class="direction-header">
            <div class="label">DIRECTION</div>
            <div class="name">{{ $direction['direction'] }}</div>
            <div class="owner">Directeur : {{ $direction['directeur'] }}</div>
        </div>

        @foreach ($direction['services'] as $service)
            <div class="service-wrap">
                <div class="service-header">
                    <div class="label">SERVICE</div>
                    <div class="name">{{ $service['service'] }}</div>
                    <div class="owner">Chef de service : {{ $service['chef'] }}</div>
                </div>

                @foreach ($service['blocks'] as $block)
                    <div class="oo-block">
                        <table class="oo-table">
                            <colgroup>
                                <col class="col-description"><col class="col-rmo"><col class="col-cible">
                                <col class="col-debut"><col class="col-fin"><col class="col-etat">
                                <col class="col-ressources"><col class="col-indicateurs"><col class="col-taux">
                                <col class="col-risques">
                            </colgroup>
                            <tbody>
                                <tr class="band-key"><td colspan="10">AXE STRATÉGIQUE</td></tr>
                                <tr class="band-value"><td colspan="10">{{ $block['axe'] }}</td></tr>
                                <tr class="band-key"><td colspan="10">OBJECTIF STRATÉGIQUE</td></tr>
                                <tr class="band-value"><td colspan="10">{{ $block['objectif_strategique'] }}</td></tr>
                                <tr class="band-key"><td colspan="10">OBJECTIF OPÉRATIONNEL N° {{ $block['numero'] }}</td></tr>
                                <tr class="band-value"><td colspan="10">{{ $block['objectif_operationnel'] }}</td></tr>
                                <tr class="head">
                                    <th>DESCRIPTION DES ACTIONS DÉTAILLÉES</th>
                                    <th>RMO</th>
                                    <th>CIBLE</th>
                                    <th>DÉBUT</th>
                                    <th>FIN</th>
                                    <th>ÉTAT DE RÉALISATION</th>
                                    <th>RESSOURCES REQUISES</th>
                                    <th>INDICATEURS DE PERFORMANCE</th>
                                    <th>TAUX D'EXÉCUTION</th>
                                    <th>RISQUES POTENTIELS</th>
                                </tr>
                                @forelse ($block['actions'] as $action)
                                    <tr>
                                        <td>{{ $action['libelle'] ?? '-' }}</td>
                                        <td>{{ $action['responsable'] ?? '-' }}</td>
                                        <td class="cell-center">{{ $action['cible'] ?? '-' }}</td>
                                        <td class="cell-center">{{ $action['debut_label'] ?? '-' }}</td>
                                        <td class="cell-center">{{ $action['fin_label'] ?? '-' }}</td>
                                        <td class="cell-center">{{ $action['statut_action_label'] ?? '-' }}</td>
                                        <td>{{ $action['ressources_requises'] ?? '-' }}</td>
                                        <td>{{ $action['livrable_attendu_label'] ?? '-' }}</td>
                                        <td class="cell-rate">{{ $action['taux_realisation_label'] ?? '-' }}</td>
                                        <td>{{ $action['risques_potentiels'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="10" class="empty-block">Aucune action rattachée à cet objectif opérationnel.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        @endforeach
    @empty
        <p class="empty-block">Aucune action disponible pour les filtres actifs.</p>
    @endforelse
</body>
</html>
