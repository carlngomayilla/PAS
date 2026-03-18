<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reporting consolide ANBG</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
            font-size: 12px;
        }
        h1, h2 {
            margin: 0 0 8px;
        }
        .meta {
            margin-bottom: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        th, td {
            border: 1px solid #d1d5db;
            padding: 6px;
            vertical-align: top;
        }
        th {
            background: #f3f4f6;
            text-align: left;
        }
        .section {
            margin-top: 12px;
        }
        .compact th,
        .compact td {
            font-size: 9px;
            padding: 4px;
        }
    </style>
</head>
<body>
    <h1>Reporting consolide ANBG</h1>
    <p class="meta">
        Genere le {{ $generatedAt->format('Y-m-d H:i:s') }} |
        Role: {{ $scope['role'] }} |
        Direction: {{ $scope['direction_id'] ?? '-' }} |
        Service: {{ $scope['service_id'] ?? '-' }}
    </p>

    <div class="section">
        <h2>Indicateurs globaux</h2>
        <table>
            <thead>
                <tr>
                    <th>Indicateur</th>
                    <th>Valeur</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($global as $key => $value)
                    <tr>
                        <td>{{ $key }}</td>
                        <td>{{ $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Statuts</h2>
        <table>
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Statut</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statuts as $module => $rows)
                    @foreach ($rows as $status => $total)
                        <tr>
                            <td>{{ strtoupper($module) }}</td>
                            <td>{{ $status }}</td>
                            <td>{{ $total }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Alertes de synthese</h2>
        <table>
            <thead>
                <tr>
                    <th>Alerte</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($alertes as $label => $count)
                    <tr>
                        <td>{{ $label }}</td>
                        <td>{{ $count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Vue consolidee du PAS</h2>
        <table>
            <thead>
                <tr>
                    <th>PAS</th>
                    <th>Periode</th>
                    <th>Axes</th>
                    <th>Objectifs</th>
                    <th>PAO</th>
                    <th>PTA</th>
                    <th>Actions</th>
                    <th>Validees</th>
                    <th>Taux</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pasConsolidation as $row)
                    <tr>
                        <td>{{ $row['titre'] }}</td>
                        <td>{{ $row['periode'] }}</td>
                        <td>{{ $row['axes_total'] }}</td>
                        <td>{{ $row['objectifs_total'] }}</td>
                        <td>{{ $row['paos_total'] }}</td>
                        <td>{{ $row['ptas_total'] }}</td>
                        <td>{{ $row['actions_total'] }}</td>
                        <td>{{ $row['actions_validees'] }}</td>
                        <td>{{ number_format((float) $row['taux_realisation'], 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">Aucune consolidation disponible.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
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
                    <th>Progression</th>
                    <th>Taux validation</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($interannualComparison as $row)
                    <tr>
                        <td>{{ $row['annee'] }}</td>
                        <td>{{ $row['paos_total'] }}</td>
                        <td>{{ $row['ptas_total'] }}</td>
                        <td>{{ $row['actions_total'] }}</td>
                        <td>{{ $row['actions_validees'] }}</td>
                        <td>{{ $row['actions_retard'] }}</td>
                        <td>{{ number_format((float) $row['progression_moyenne'], 2) }}%</td>
                        <td>{{ number_format((float) $row['taux_validation'], 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">Aucune comparaison disponible.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Details actions en retard</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Action</th>
                    <th>Echeance</th>
                    <th>Statut</th>
                    <th>PTA</th>
                    <th>Responsable</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($details['actions_retard'] as $action)
                    <tr>
                        <td>{{ $action->id }}</td>
                        <td>{{ $action->libelle }}</td>
                        <td>{{ optional($action->date_echeance)->format('Y-m-d') ?? '-' }}</td>
                        <td>{{ $action->statut_dynamique }}</td>
                        <td>{{ $action->pta?->titre ?? '-' }}</td>
                        <td>{{ $action->responsable?->name ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">Aucune action en retard.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Details KPI sous seuil</h2>
        <table>
            <thead>
                <tr>
                    <th>ID mesure</th>
                    <th>KPI</th>
                    <th>Periode</th>
                    <th>Valeur</th>
                    <th>Seuil</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($details['kpi_sous_seuil'] as $mesure)
                    <tr>
                        <td>{{ $mesure->id }}</td>
                        <td>{{ $mesure->kpi?->libelle ?? '-' }}</td>
                        <td>{{ $mesure->periode }}</td>
                        <td>{{ $mesure->valeur }}</td>
                        <td>{{ $mesure->kpi?->seuil_alerte ?? '-' }}</td>
                        <td>{{ $mesure->kpi?->action?->libelle ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">Aucune mesure sous seuil.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Structure des rapports strategiques</h2>
        <table class="compact">
            <thead>
                <tr>
                    <th>Axe strategique</th>
                    <th>Objectif strategique</th>
                    <th>Objectif operationnel</th>
                    <th>Description actions detaillees</th>
                    <th>RMO</th>
                    <th>Cible</th>
                    <th>Debut</th>
                    <th>Fin</th>
                    <th>Etat</th>
                    <th>Prog.</th>
                    <th>Ressources</th>
                    <th>Indicateurs</th>
                    <th>Risques</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($details['structure_rapports'] as $row)
                    <tr>
                        <td>{{ $row['axe_strategique'] ?: '-' }}</td>
                        <td>{{ $row['objectif_strategique'] ?: '-' }}</td>
                        <td>{{ $row['objectif_operationnel'] ?: '-' }}</td>
                        <td>{{ $row['description_actions_detaillees'] ?: '-' }}</td>
                        <td>{{ $row['rmo'] ?: '-' }}</td>
                        <td>{{ $row['cible'] ?: '-' }}</td>
                        <td>{{ $row['debut'] ?: '-' }}</td>
                        <td>{{ $row['fin'] ?: '-' }}</td>
                        <td>{{ $row['etat_realisation'] ?: '-' }}</td>
                        <td>{{ $row['progression'] ?: '-' }}</td>
                        <td>{{ $row['ressources_requises'] ?: '-' }}</td>
                        <td>{{ $row['indicateurs_performance'] ?: '-' }}</td>
                        <td>{{ $row['risques_potentiels'] ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="13">Aucune ligne de structure disponible.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
