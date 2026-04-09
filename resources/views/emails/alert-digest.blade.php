<!DOCTYPE html>
<html lang="{{ $platformSettings->htmlLang() }}">
<head>
    <meta charset="UTF-8">
    <title>Alerte ANBG</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.45;">
    <h2 style="margin-bottom: 8px;">Alerte automatique ANBG</h2>
    <p style="margin-top: 0;">
        Bonjour {{ $user->name }},<br>
        voici le point des alertes de votre perimetre au {{ $digest['generated_at']->format('Y-m-d H:i') }}.
    </p>

    <table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse; margin: 12px 0;">
        <thead>
            <tr style="background: #f3f4f6;">
                <th align="left">Type alerte</th>
                <th align="right">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Actions en retard</td>
                <td align="right">{{ $digest['totals']['actions_retard'] }}</td>
            </tr>
            <tr>
                <td>Indicateurs sous seuil</td>
                <td align="right">{{ $digest['totals']['kpi_sous_seuil'] }}</td>
            </tr>
            <tr>
                <td>Alertes hebdomadaires</td>
                <td align="right">{{ $digest['totals']['action_logs'] ?? 0 }}</td>
            </tr>
            <tr style="font-weight: bold;">
                <td>Total</td>
                <td align="right">{{ $digest['totals']['total_alertes'] }}</td>
            </tr>
        </tbody>
    </table>

    @if ($digest['actions_retard']->isNotEmpty())
        <h3 style="margin-bottom: 6px;">Actions en retard</h3>
        <ul>
            @foreach ($digest['actions_retard'] as $action)
                <li>
                    {{ $action->libelle }} |
                    echeance: {{ optional($action->date_echeance)->format('Y-m-d') ?? '-' }} |
                    statut: {{ $action->statut_dynamique }} |
                    responsable: {{ $action->responsable?->name ?? '-' }}
                </li>
            @endforeach
        </ul>
    @endif

    @if (($digest['action_logs'] ?? collect())->isNotEmpty())
        <h3 style="margin-bottom: 6px;">Alertes hebdomadaires</h3>
        <ul>
            @foreach ($digest['action_logs'] as $log)
                <li>
                    [{{ strtoupper($log->niveau) }}] {{ $log->message }} |
                    action: {{ $log->action?->libelle ?? '-' }} |
                    semaine: {{ $log->week?->numero_semaine ?? '-' }}
                </li>
            @endforeach
        </ul>
    @endif

    @if ($digest['kpi_sous_seuil']->isNotEmpty())
        <h3 style="margin-bottom: 6px;">Indicateurs sous seuil</h3>
        <ul>
            @foreach ($digest['kpi_sous_seuil'] as $mesure)
                <li>
                    {{ $mesure->kpi?->libelle ?? 'Indicateur' }} |
                    valeur: {{ $mesure->valeur }} |
                    seuil: {{ $mesure->kpi?->seuil_alerte ?? '-' }} |
                    periode: {{ $mesure->periode }}
                </li>
            @endforeach
        </ul>
    @endif

    <p>
        Acces direct: <a href="{{ url('/workspace/alertes') }}">{{ url('/workspace/alertes') }}</a>
    </p>
    <p style="color: #6b7280; font-size: 12px;">
        Message automatique ANBG - PAS/PAO/PTA.
    </p>
</body>
</html>
