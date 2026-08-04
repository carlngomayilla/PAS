<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { size:A4 landscape; margin:8mm; }
        body { font-family:DejaVu Sans, sans-serif; color:#000; font-size:8px; margin:0; }
        .report-top { display:table; width:100%; border-collapse:collapse; margin-bottom:8px; }
        .report-logo, .report-title { display:table-cell; vertical-align:middle; border:1px solid #999; }
        .report-logo { width:20%; padding:5px; }
        .report-logo img { width:90px; }
        .report-title { width:80%; text-align:center; background:#bdd7ee; font-size:14px; font-weight:900; padding:6px; }
        .report-meta { margin:4px 0 10px; font-size:8px; font-weight:700; color:#1f2937; }
        .oo-block { margin-bottom:10px; page-break-inside:avoid; }
        table.oo-table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:6.5px; }
        table.oo-table th, table.oo-table td { border:1px solid #111; padding:3px; vertical-align:top; overflow-wrap:break-word; }
        .band-key td { background:#1f4e79; color:#fff; font-weight:900; text-align:center; font-size:7px; letter-spacing:0.3px; }
        .band-value td { background:#deeaf6; color:#0f2f57; font-weight:800; text-align:center; font-size:7.5px; }
        table.oo-table thead th { background:#d9d9d9; color:#000; font-weight:900; text-align:center; font-size:6.5px; }
        .col-description { width:20%; }
        .col-rmo { width:9%; }
        .col-cible { width:6%; }
        .col-debut { width:6%; }
        .col-fin { width:6%; }
        .col-etat { width:9%; }
        .col-ressources { width:16%; }
        .col-indicateurs { width:16%; }
        .col-risques { width:12%; }
        .cell-center { text-align:center; }
        .empty-block { text-align:center; color:#666; font-style:italic; padding:6px; }
    </style>
</head>
<body>
    @php
        $logoPath = public_path('images/logo-wordmark.png');
        $logoData = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)) : null;
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

    @php $hasBlock = false; @endphp

    @foreach ($groups as $pasGroup)
        @foreach (($pasGroup['axes'] ?? []) as $axisGroup)
            @foreach (($axisGroup['objectifs'] ?? []) as $strategicGroup)
                @foreach (($strategicGroup['objectifs_operationnels'] ?? []) as $operationalIndex => $operationalGroup)
                    @php
                        $actions = collect($operationalGroup['actions'] ?? []);
                        $hasBlock = true;
                    @endphp
                    <div class="oo-block">
                        <table class="oo-table">
                            <colgroup>
                                <col class="col-description"><col class="col-rmo"><col class="col-cible">
                                <col class="col-debut"><col class="col-fin"><col class="col-etat">
                                <col class="col-ressources"><col class="col-indicateurs"><col class="col-risques">
                            </colgroup>
                            <tbody>
                                <tr class="band-key"><td colspan="9">AXE STRATÉGIQUE</td></tr>
                                <tr class="band-value"><td colspan="9">{{ $axisGroup['label'] ?? '-' }}</td></tr>
                                <tr class="band-key"><td colspan="9">OBJECTIF STRATÉGIQUE</td></tr>
                                <tr class="band-value"><td colspan="9">{{ $strategicGroup['label'] ?? '-' }}</td></tr>
                                <tr class="band-key"><td colspan="9">OBJECTIF OPÉRATIONNEL N° {{ $operationalIndex + 1 }}</td></tr>
                                <tr class="band-value"><td colspan="9">{{ $operationalGroup['label'] ?? '-' }}</td></tr>
                                <tr>
                                    <th>DESCRIPTION DES ACTIONS DÉTAILLÉES</th>
                                    <th>RMO</th>
                                    <th>CIBLE</th>
                                    <th>DÉBUT</th>
                                    <th>FIN</th>
                                    <th>ÉTAT DE RÉALISATION</th>
                                    <th>RESSOURCES REQUISES</th>
                                    <th>INDICATEURS DE PERFORMANCE</th>
                                    <th>RISQUES POTENTIELS</th>
                                </tr>
                                @forelse ($actions as $action)
                                    <tr>
                                        <td>{{ $action['libelle'] ?? '-' }}</td>
                                        <td>{{ $action['responsable'] ?? '-' }}</td>
                                        <td class="cell-center">{{ $action['cible'] ?? '-' }}</td>
                                        <td class="cell-center">{{ $action['debut_label'] ?? '-' }}</td>
                                        <td class="cell-center">{{ $action['fin_label'] ?? '-' }}</td>
                                        <td class="cell-center">{{ $action['statut_action_label'] ?? '-' }}</td>
                                        <td>{{ $action['ressources_requises'] ?? '-' }}</td>
                                        <td>{{ $action['indicateur'] ?? '-' }}</td>
                                        <td>{{ $action['risques_potentiels'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="empty-block">Aucune action rattachée à cet objectif opérationnel.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @endforeach
        @endforeach
    @endforeach

    @if (! $hasBlock)
        <p class="empty-block">Aucune action disponible pour les filtres actifs.</p>
    @endif
</body>
</html>
