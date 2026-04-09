@php
    $exportTemplate = $exportTemplate ?? null;
    $templateBlocks = $exportTemplate?->blocks_config ?? [];
    $templateLayout = $exportTemplate?->layout_config ?? [];
    $title = $exportTemplate?->documentTitle() ?? 'Reporting ANBG';
    $subtitle = $exportTemplate?->documentSubtitle();
    $officialPolicy = is_array($officialPolicy ?? null) ? $officialPolicy : [];
    $officialBaseLabel = (string) ($officialPolicy['threshold_label'] ?? 'Toutes les actions visibles');
    $global = $global ?? [];
    $alertes = $alertes ?? [];
    $interannualComparison = $interannualComparison ?? [];
    $details = $details ?? [];
    $showWordToc = (bool) ($templateLayout['word_include_toc'] ?? false);
    $wordSummaryBreak = (bool) ($templateLayout['word_page_break_after_summary'] ?? false);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body{font-family:"Times New Roman",serif;color:#1f2937;font-size:12pt;line-height:1.5;margin:28px}
        h1,h2,h3{color:#1e3a8a;margin:0 0 10px}
        h1{font-size:24pt}
        h2{font-size:16pt;margin-top:22px}
        h3{font-size:13pt;margin-top:16px}
        .meta{font-size:10pt;color:#475569;margin-bottom:18px}
        .badge{display:inline-block;padding:4px 10px;border:1px solid #cbd5e1;border-radius:999px;font-size:9pt;margin-right:8px}
        table{width:100%;border-collapse:collapse;margin-top:12px}
        th,td{border:1px solid #cbd5e1;padding:8px;vertical-align:top}
        th{background:#eff6ff;text-align:left}
        .summary-grid{width:100%;margin-top:10px}
        .summary-grid td{width:25%}
        .muted{color:#64748b}
        .watermark{font-size:10pt;color:#94a3b8;margin-top:8px}
        .page-break{page-break-after:always}
    </style>
</head>
<body>
    @if (($templateBlocks['include_cover'] ?? true) === true)
        <p class="meta">{{ $templateLayout['header_text'] ?? 'ANBG' }}</p>
        <h1>{{ $title }}</h1>
        @if ($subtitle)
            <p class="meta">{{ $subtitle }}</p>
        @endif
        <p class="meta">Base statistique : {{ $officialBaseLabel }}</p>
        <p class="meta">Genere le {{ isset($generatedAt) && $generatedAt instanceof \Illuminate\Support\Carbon ? $generatedAt->format('d/m/Y H:i') : now()->format('d/m/Y H:i') }}</p>
        @if (! empty($templateLayout['watermark_text'] ?? ''))
            <p class="watermark">{{ $templateLayout['watermark_text'] }}</p>
        @endif
    @endif

    @if ($showWordToc)
        <h2>Sommaire</h2>
        <table>
            <tr><th>Section</th><th>Contenu</th></tr>
            <tr><td>01</td><td>Synthese executive</td></tr>
            <tr><td>02</td><td>Cadre de lecture</td></tr>
            <tr><td>03</td><td>Comparaison interannuelle</td></tr>
            <tr><td>04</td><td>Alertes de synthese</td></tr>
            <tr><td>05</td><td>Details actions en retard</td></tr>
        </table>
    @endif

    @if (($templateBlocks['include_summary'] ?? true) === true)
        <div @class(['page-break' => $wordSummaryBreak])>
            <h2>Synthese executive</h2>
            <table class="summary-grid">
                <tr>
                    <th>Actions</th>
                    <th>Actions validees</th>
                    <th>Actions en retard</th>
                    <th>Alertes actives</th>
                </tr>
                <tr>
                    <td>{{ $global['actions_total'] ?? 0 }}</td>
                    <td>{{ $global['actions_validees'] ?? 0 }}</td>
                    <td>{{ $alertes['actions_en_retard'] ?? 0 }}</td>
                    <td>{{ ($alertes['actions_en_retard'] ?? 0) + ($alertes['mesures_kpi_sous_seuil'] ?? 0) }}</td>
                </tr>
            </table>
        </div>
    @endif

    <h2>Cadre de lecture</h2>
    <p>Ce document reprend le reporting du PAS avec une lecture statistique unifiee. La base statistique actuellement appliquee est : <strong>{{ $officialBaseLabel }}</strong>.</p>

    @if (($templateBlocks['include_detail_table'] ?? true) === true)
        <h2>Comparaison interannuelle</h2>
        <table>
            <thead>
                <tr>
                    <th>Annee</th>
                    <th>PAO</th>
                    <th>PTA</th>
                    <th>Actions</th>
                    <th>Validees</th>
                    <th>Retard</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($interannualComparison as $row)
                    <tr>
                        <td>{{ $row['annee'] ?? '-' }}</td>
                        <td>{{ $row['paos_total'] ?? 0 }}</td>
                        <td>{{ $row['ptas_total'] ?? 0 }}</td>
                        <td>{{ $row['actions_total'] ?? 0 }}</td>
                        <td>{{ $row['actions_validees'] ?? 0 }}</td>
                        <td>{{ $row['actions_retard'] ?? 0 }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Aucune comparaison disponible.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if (($templateBlocks['include_alerts'] ?? true) === true)
        <h2>Alertes de synthese</h2>
        <table>
            <tr>
                <th>Type</th>
                <th>Volume</th>
            </tr>
            <tr><td>Actions en retard</td><td>{{ $alertes['actions_en_retard'] ?? 0 }}</td></tr>
            <tr><td>Mesures KPI sous seuil</td><td>{{ $alertes['mesures_kpi_sous_seuil'] ?? 0 }}</td></tr>
            <tr><td>Journaux critiques</td><td>{{ $alertes['action_logs'] ?? 0 }}</td></tr>
        </table>
    @endif

    @if (($templateBlocks['include_detail_table'] ?? true) === true)
        <h2>Details actions en retard</h2>
        <table>
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Echeance</th>
                    <th>Statut</th>
                    <th>Responsable</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($details['actions_retard'] ?? []) as $action)
                    <tr>
                        <td>{{ $action->libelle }}</td>
                        <td>{{ optional($action->date_echeance)->format('Y-m-d') ?? '-' }}</td>
                        <td>{{ $action->statut_dynamique }}</td>
                        <td>{{ $action->responsable?->name ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Aucune action en retard sur le scope courant.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if (($templateBlocks['include_signatures'] ?? false) === true)
        <h2>Visa et signatures</h2>
        <p class="muted">{{ $templateLayout['footer_text'] ?? 'Document de diffusion interne' }}</p>
    @endif
</body>
</html>

