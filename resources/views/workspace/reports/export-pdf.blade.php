<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #17324a; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        h2 { font-size: 13px; margin: 22px 0 8px; }
        .meta { color: #526b84; margin-bottom: 18px; }
        .kpis { width: 100%; border-collapse: collapse; margin: 12px 0 20px; }
        .kpis td { background: #edf6fb; border: 1px solid #c8dce8; padding: 10px; width: 25%; }
        .kpis strong { display: block; font-size: 18px; margin-top: 4px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #17324a; color: #fff; font-size: 9px; text-align: left; }
        th, td { border: 1px solid #cbd5e1; padding: 7px; vertical-align: top; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
    <h1>Rapport de suivi des réunions</h1>
    <p class="meta">Périmètre : {{ collect(['year' => 'Exercice', 'quarter' => 'Trimestre', 'month' => 'Mois'])->map(fn ($label, $key) => ! empty($filters[$key]) ? $label.' '.$filters[$key] : null)->filter()->join(' | ') ?: 'Tous les périmètres accessibles' }}<br>Généré le {{ now()->format('d/m/Y à H:i') }}</p>

    <table class="kpis"><tr>
        <td>Réunions programmées<strong>{{ $summary['meetings_scheduled'] ?? 0 }}</strong></td>
        <td>Réunions tenues<strong>{{ $summary['meetings_held'] ?? 0 }}</strong></td>
        <td>Non tenues à échéance<strong>{{ $summary['meetings_overdue'] ?? 0 }}</strong></td>
        <td>PV diffusés<strong>{{ $summary['minutes_distributed'] ?? 0 }}</strong></td>
    </tr><tr>
        <td>Reportées<strong>{{ $summary['meetings_postponed'] ?? 0 }}</strong></td>
        <td>Annulées<strong>{{ $summary['meetings_cancelled'] ?? 0 }}</strong></td>
        <td>Décisions à suivre<strong>{{ $summary['meeting_decisions_open'] ?? 0 }}</strong></td>
        <td>Taux de tenue<strong>{{ number_format((float) ($summary['meeting_completion_rate'] ?? 0), 0, ',', ' ') }} %</strong></td>
    </tr></table>

    <h2>Réunions</h2>
    <table>
        <thead><tr><th>Réunion</th><th>Périmètre</th><th>Programmation</th><th>État</th><th>Responsable</th><th>PV</th></tr></thead>
        <tbody>
            @foreach ($meetings as $meeting)
                @php
                    $state = $meeting->cancelled_at !== null ? 'Annulée' : ($meeting->held_at !== null ? ($meeting->scheduled_at !== null && $meeting->held_at->lte($meeting->scheduled_at) ? 'Tenue dans les délais' : 'Tenue hors délai') : ($meeting->scheduled_at !== null && $meeting->scheduled_at->isPast() ? 'Non tenue à échéance' : 'Programmée'));
                @endphp
                <tr>
                    <td><strong>{{ $meeting->title }}</strong><br><span class="muted">{{ $meeting->meeting_type === 'service' ? 'Réunion de service' : 'Réunion de direction' }}</span></td>
                    <td>{{ $meeting->direction?->code ?? 'Agence' }}@if ($meeting->service) · {{ $meeting->service->code }}@endif</td>
                    <td>{{ $meeting->scheduled_at?->format('d/m/Y H:i') ?? '-' }}</td>
                    <td>{{ $state }}</td>
                    <td>{{ $meeting->responsible?->name ?? $meeting->submittedBy?->name ?? '-' }}</td>
                    <td>{{ $meeting->minutes_published_at?->format('d/m/Y H:i') ?? 'En attente' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
