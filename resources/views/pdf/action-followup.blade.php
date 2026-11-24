<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Fiche de suivi de l'action</title>
    <style>
        @page { margin: 24px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #081d35; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border: 1px solid #26394c; padding: 6px; vertical-align: top; }
        .header-logo { width: 88px; text-align: center; }
        .header-logo img { max-width: 70px; max-height: 45px; }
        .title { background: #cfe1f4; text-align: center; font-size: 16px; font-weight: 700; }
        .meta { color: #0076bb; font-weight: 700; }
        .section { background: #edf5fc; text-align: center; font-weight: 700; }
        .label { font-weight: 700; }
        .center { text-align: center; vertical-align: middle; }
        .execution { min-height: 82px; line-height: 1.55; }
        .signatures td { width: 50%; height: 118px; position: relative; }
        .signature-image { display: block; max-width: 150px; max-height: 45px; margin: 8px auto 3px; }
        .signature-name { text-align: center; font-weight: 700; }
        .signature-date { text-align: center; color: #40566f; font-size: 8px; }
        .missing { margin-top: 28px; text-align: center; color: #7c8794; font-style: italic; }
        .footer { margin-top: 8px; color: #52677d; font-size: 8px; }
        .status { float: right; padding: 3px 7px; border: 1px solid #1d72b8; border-radius: 9px; color: #1d72b8; }
    </style>
</head>
<body>
@php
    $objective = $action->objectifOperationnel;
    $strategicObjective = $objective?->pasObjectif ?? $action->pao?->pasObjectif;
    $axis = $strategicObjective?->pasAxe;
    $year = $action->exercice?->annee ?? $action->date_fin?->year ?? now()->year;
    $target = $action->quantite_cible ?? $action->quantite_a_realiser ?? $action->cible;
    $indicator = $action->primaryKpi?->libelle ?: $action->indicateurs_attendus ?: $action->intitule_cible;
    $progress = number_format((float) ($action->official_progress_percent ?? $action->progression_reelle ?? 0), 2, ',', ' ');
    $quantity = number_format((float) ($action->quantite_realisee ?? 0), 2, ',', ' ');
@endphp
<table>
    <tr>
        <td class="header-logo">@if($logoDataUri)<img src="{{ $logoDataUri }}" alt="ANBG">@else<strong>ANBG</strong>@endif</td>
        <td class="title">PLAN DE TRAVAIL ANNUEL {{ $year }}</td>
    </tr>
    <tr><td colspan="2" class="meta">Axe stratégique : {{ $axis?->code }} {{ $axis?->libelle ?: 'Non renseigné' }}</td></tr>
    <tr><td colspan="2" class="meta">Objectif stratégique : {{ $strategicObjective?->code }} {{ $strategicObjective?->libelle ?: 'Non renseigné' }}</td></tr>
    <tr><td colspan="2"><span class="label">Objectif opérationnel :</span> {{ $objective?->code }} {{ $objective?->libelle ?: $action->pao?->objectif_operationnel ?: 'Non renseigné' }}</td></tr>
</table>

<table>
    <tr>
        <th style="width: 39%">Intitulé de l'action</th>
        <th style="width: 35%">Indicateur de mesure</th>
        <th style="width: 12%">Cible</th>
        <th style="width: 14%">Échéance</th>
    </tr>
    <tr>
        <td class="center">{{ $action->libelle }}</td>
        <td class="center">{{ $indicator ?: 'Non renseigné' }}</td>
        <td class="center">{{ $target !== null ? $target.' '.($action->unite_cible ?? '') : 'Non renseignée' }}</td>
        <td class="center">{{ $action->date_fin?->format('d/m/Y') ?? $action->date_echeance?->format('d/m/Y') ?? 'Non renseignée' }}</td>
    </tr>
    <tr><td colspan="4" class="section">Avancement de l'action</td></tr>
    <tr>
        <td colspan="4" class="execution">
            <strong>Avancement validé :</strong> {{ $progress }} %<br>
            <strong>Quantité réalisée :</strong> {{ $quantity }} {{ $action->unite_cible }}<br>
            <strong>Résultat obtenu :</strong> {{ $action->resultat_cloture ?: $action->resultat_attendu ?: 'Non renseigné' }}<br>
            <strong>Difficultés rencontrées :</strong> {{ $action->difficultes_rencontrees ?: 'Aucune difficulté renseignée' }}<br>
            <strong>Mesures correctives :</strong> {{ $action->mesures_correctives ?: 'Aucune mesure renseignée' }}
            <span class="status">{{ $stageLabel }}</span>
        </td>
    </tr>
</table>

<table class="signatures">
    <tr>
        @include('pdf.partials.action-signature', ['title' => 'Visa du RMO', 'receipt' => $receipts->get('rmo')])
        @include('pdf.partials.action-signature', ['title' => 'Visa du Chef de service', 'receipt' => $receipts->get('chef_service')])
    </tr>
    <tr>
        @include('pdf.partials.action-signature', ['title' => 'Visa de la Planification', 'receipt' => $receipts->get('planification')])
        @include('pdf.partials.action-signature', ['title' => 'Visa du SCIQ (si contrôle ouvert)', 'receipt' => $receipts->get('sciq')])
    </tr>
</table>

<div class="footer">
    Fiche générée automatiquement le {{ $generatedAt->format('d/m/Y à H:i') }} — version {{ $version }} — action {{ $action->code ?: '#'.$action->id }}.
    Les visas sont des instantanés immuables enregistrés au moment de chaque validation.
</div>
</body>
</html>
