@php
    $variant = (string) ($variant ?? 'word');
    $isPdf = $variant === 'pdf';
    $sectionClass = $isPdf ? 'section page-break-section' : 'section-break';
    $entityLabel = static function (array $entity, string $fallback): string {
        $code = trim((string) ($entity['code'] ?? ''));
        $label = trim((string) ($entity['libelle'] ?? $fallback));

        return $code !== '' ? $code.' - '.$label : ($label !== '' ? $label : $fallback);
    };
    $generatedAtValue = $generatedAt ?? now();
    $generatedAtLabel = $generatedAtValue instanceof \Carbon\CarbonInterface
        ? $generatedAtValue->format('d/m/Y H:i')
        : now()->format('d/m/Y H:i');
    $generatedYear = $generatedAtValue instanceof \Carbon\CarbonInterface
        ? $generatedAtValue->format('Y')
        : now()->format('Y');
    $defaultPeriodLabel = 'Exercice '.$generatedYear;
    $tablePeriodLabel = static function ($rows, string $fallback) {
        $dates = collect($rows)
            ->flatMap(static function (array $row): array {
                return [
                    (string) ($row['debut'] ?? ''),
                    (string) ($row['fin'] ?? ''),
                    (string) ($row['echeance'] ?? ''),
                    (string) ($row['echeance_strategique'] ?? ''),
                ];
            })
            ->map(static fn ($value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1)
            ->sort()
            ->values();

        if ($dates->isEmpty()) {
            return $fallback;
        }

        $start = (string) $dates->first();
        $end = (string) $dates->last();

        return $start === $end ? $start : $start.' au '.$end;
    };
@endphp

@if (($templateBlocks['include_detail_table'] ?? true) === true)
    <div class="{{ $sectionClass }}">
        @if ($isPdf)
            <span class="section-kicker">Tableau 1</span><h2>Axes &amp; Objectifs stratégiques</h2>
        @else
            <div class="blue-band">Tableau 1 : Axes &amp; Objectifs stratégiques</div>
        @endif

        @include('workspace.monitoring.partials.reporting-table-header', [
            'scopeLabel' => 'Périmètre',
            'scopeValue' => 'Consolidé ANBG',
            'tableTitle' => 'Axes et Objectifs stratégiques',
            'periodLabel' => $tablePeriodLabel($strategyRows, $defaultPeriodLabel),
            'generatedAtLabel' => $generatedAtLabel,
        ])

        <table>
            <thead>
                <tr>
                    <th>N° Axe</th>
                    <th>Axe stratégique</th>
                    <th>N° Obj</th>
                    <th>Objectif stratégique</th>
                    <th>Échéance</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($strategyRows as $row)
                    <tr>
                        <td>{{ $row['axe_numero'] }}</td>
                        <td>{{ $row['axe'] }}</td>
                        <td>{{ $row['objectif_numero'] }}</td>
                        <td>{{ $row['objectif'] }}</td>
                        <td class="nowrap">{{ $row['echeance'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Aucune donnée stratégique disponible.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @forelse ($directionServiceReport as $direction)
        @php
            $direction = (array) $direction;
            $directionLabel = $entityLabel($direction, 'Direction');
            $directionResponsable = (string) ($direction['responsable'] ?? '-');
            $directionSummary = (array) ($direction['summary'] ?? []);
            $services = collect($direction['services'] ?? []);
            $directionActions = $services
                ->flatMap(static fn (array $service): array => array_values((array) ($service['actions'] ?? [])))
                ->values();
            $directionPeriodLabel = $tablePeriodLabel($directionActions, $defaultPeriodLabel);
        @endphp

        <div class="{{ $sectionClass }}">
            @if ($isPdf)
                <span class="section-kicker">Direction</span><h2>{{ $directionLabel }}</h2>
            @else
                <div class="blue-band">Direction : {{ $directionLabel }}</div>
            @endif

            @include('workspace.monitoring.partials.reporting-table-header', [
                'directionLabel' => $directionLabel,
                'directionResponsable' => $directionResponsable,
                'tableTitle' => 'Reporting synthétique de direction',
                'periodLabel' => $directionPeriodLabel,
                'generatedAtLabel' => $generatedAtLabel,
            ])

            <table class="compact">
                <thead>
                    <tr>
                        <th>Responsable</th>
                        <th>Total actions</th>
                        <th>Terminées</th>
                        <th>En cours</th>
                        <th>En retard</th>
                        <th>Performance (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $directionResponsable }}</td>
                        <td>{{ $directionSummary['actions_total'] ?? 0 }}</td>
                        <td>{{ $directionSummary['actions_terminees'] ?? 0 }}</td>
                        <td>{{ $directionSummary['actions_en_cours'] ?? 0 }}</td>
                        <td>{{ $directionSummary['actions_retard'] ?? 0 }}</td>
                        <td>{{ number_format((float) ($directionSummary['taux_realisation'] ?? 0), 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @forelse ($services as $service)
            @php
                $service = (array) $service;
                $serviceLabel = $entityLabel($service, 'Service');
                $serviceResponsable = (string) ($service['responsable'] ?? '-');
                $serviceSummary = (array) ($service['summary'] ?? []);
                $serviceActions = collect($service['actions'] ?? [])->values();
                $servicePeriodLabel = $tablePeriodLabel($serviceActions, $defaultPeriodLabel);
                $serviceRiskRows = $serviceActions
                    ->filter(fn (array $row): bool => trim((string) ($row['risque_identifie'] ?? '')) !== '')
                    ->values();
                $serviceRmoRows = $serviceActions
                    ->groupBy(fn (array $row): string => (string) ($row['rmo'] ?? $row['responsable'] ?? 'Non renseigné'))
                    ->map(function ($rows, string $rmo): array {
                        return [
                            'rmo' => $rmo !== '' ? $rmo : 'Non renseigné',
                            'total' => $rows->count(),
                            'performance' => round((float) $rows->avg(fn (array $row): float => (float) ($row['kpi_performance_value'] ?? 0)), 2),
                        ];
                    })
                    ->sortBy(fn (array $row): string => sprintf('%09.2f', 10000 - (float) $row['performance']).'|'.$row['rmo'])
                    ->values();
                $serviceJustificatifRows = $serviceActions
                    ->flatMap(function (array $row): array {
                        $justificatifs = (array) ($row['justificatifs'] ?? []);
                        if ($justificatifs === []) {
                            return [[
                                'action' => (string) ($row['action'] ?? '-'),
                                'rmo' => (string) ($row['rmo'] ?? $row['responsable'] ?? '-'),
                                'justificatif' => '-',
                                'statut' => (string) ($row['statut_validation'] ?? '-'),
                                'date' => '',
                            ]];
                        }

                        return collect($justificatifs)
                            ->map(fn (array $justificatif): array => [
                                'action' => (string) ($row['action'] ?? '-'),
                                'rmo' => (string) ($row['rmo'] ?? $row['responsable'] ?? '-'),
                                'justificatif' => (string) ($justificatif['nom'] ?? '-'),
                                'statut' => (string) ($row['statut_validation'] ?? '-'),
                                'date' => (string) ($justificatif['date'] ?? ''),
                            ])
                            ->all();
                    })
                    ->values();
            @endphp

            <div class="{{ $sectionClass }}">
                @if ($isPdf)
                    <span class="section-kicker">Service</span><h2>{{ $directionLabel }} / {{ $serviceLabel }}</h2>
                @else
                    <div class="blue-band">Service : {{ $serviceLabel }} — {{ $directionLabel }}</div>
                @endif

                @if (($templateBlocks['include_summary'] ?? true) === true)
                    @include('workspace.monitoring.partials.reporting-table-header', [
                        'directionLabel' => $directionLabel,
                        'directionResponsable' => $directionResponsable,
                        'serviceLabel' => $serviceLabel,
                        'serviceResponsable' => $serviceResponsable,
                        'tableTitle' => 'Reporting synthétique du service',
                        'periodLabel' => $servicePeriodLabel,
                        'generatedAtLabel' => $generatedAtLabel,
                    ])

                    <table class="compact">
                        <thead>
                            <tr>
                                <th>Responsable service</th>
                                <th>Total actions</th>
                                <th>Terminées</th>
                                <th>En cours</th>
                                <th>En retard</th>
                                <th>Performance (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>{{ $serviceResponsable }}</td>
                                <td>{{ $serviceSummary['actions_total'] ?? 0 }}</td>
                                <td>{{ $serviceSummary['actions_terminees'] ?? 0 }}</td>
                                <td>{{ $serviceSummary['actions_en_cours'] ?? 0 }}</td>
                                <td>{{ $serviceSummary['actions_retard'] ?? 0 }}</td>
                                <td>{{ number_format((float) ($serviceSummary['taux_realisation'] ?? 0), 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                @endif

                @include('workspace.monitoring.partials.reporting-table-header', [
                    'directionLabel' => $directionLabel,
                    'directionResponsable' => $directionResponsable,
                    'serviceLabel' => $serviceLabel,
                    'serviceResponsable' => $serviceResponsable,
                    'tableTitle' => 'Objectifs opérationnels et Actions',
                    'periodLabel' => $servicePeriodLabel,
                    'generatedAtLabel' => $generatedAtLabel,
                ])

                <table class="compact">
                    <thead>
                        <tr>
                            <th>Axe stratégique</th>
                            <th>Objectif stratégique</th>
                            <th>Objectif opérationnel</th>
                            <th>Action</th>
                            <th>Responsable</th>
                            <th>Échéance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($serviceActions as $row)
                            <tr>
                                <td>{{ $row['axe_strategique'] ?? $row['axe'] ?? '-' }}</td>
                                <td>{{ $row['objectif_strategique'] ?? $row['objectif'] ?? '-' }}</td>
                                <td>{{ $row['objectif_operationnel'] ?? '-' }}</td>
                                <td>{{ $row['action'] ?? '-' }}</td>
                                <td>{{ $row['rmo'] ?? $row['responsable'] ?? '-' }}</td>
                                <td>{{ $row['echeance'] ?? $row['fin'] ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="muted">Aucune action disponible pour ce service.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @include('workspace.monitoring.partials.reporting-table-header', [
                    'directionLabel' => $directionLabel,
                    'directionResponsable' => $directionResponsable,
                    'serviceLabel' => $serviceLabel,
                    'serviceResponsable' => $serviceResponsable,
                    'tableTitle' => 'Actions du service',
                    'periodLabel' => $servicePeriodLabel,
                    'generatedAtLabel' => $generatedAtLabel,
                ])

                <table class="compact">
                    <thead>
                        <tr>
                            <th>Description action</th>
                            <th>RMO</th>
                            <th>Cible</th>
                            <th>Début</th>
                            <th>Fin</th>
                            <th>Statut</th>
                            <th>Ressources</th>
                            <th>Taux (%)</th>
                            <th>Justificatif</th>
                            <th>Risque</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($serviceActions as $row)
                            <tr>
                                <td>{{ $row['description_action'] ?? $row['action'] ?? '-' }}</td>
                                <td>{{ $row['rmo'] ?? $row['responsable'] ?? '-' }}</td>
                                <td>{{ $row['cible'] ?? '-' }}</td>
                                <td>{{ $row['debut'] ?? '-' }}</td>
                                <td>{{ $row['fin'] ?? '-' }}</td>
                                <td>{{ $row['statut'] ?? '-' }}</td>
                                <td>{{ $row['ressources_requises'] ?? '-' }}</td>
                                <td>{{ $row['taux'] ?? '-' }}</td>
                                <td>{{ $row['justificatif'] ?? '-' }}</td>
                                <td>{{ $row['risque_identifie'] ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="muted">Aucune action détaillée disponible pour ce service.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @include('workspace.monitoring.partials.reporting-table-header', [
                    'directionLabel' => $directionLabel,
                    'directionResponsable' => $directionResponsable,
                    'serviceLabel' => $serviceLabel,
                    'serviceResponsable' => $serviceResponsable,
                    'tableTitle' => 'KPI par action',
                    'periodLabel' => $servicePeriodLabel,
                    'generatedAtLabel' => $generatedAtLabel,
                ])

                <table class="compact">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>RMO</th>
                            <th>KPI Performance (%)</th>
                            <th>KPI Qualité (%)</th>
                            <th>KPI Délai (%)</th>
                            <th>KPI Risque (%)</th>
                            <th>KPI Conformité (%)</th>
                            <th>KPI Global (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($serviceActions as $row)
                            <tr>
                                <td>{{ $row['action'] ?? '-' }}</td>
                                <td>{{ $row['rmo'] ?? $row['responsable'] ?? '-' }}</td>
                                <td>{{ $row['kpi_performance'] ?? '0.00' }}</td>
                                <td>{{ $row['kpi_qualite'] ?? '0.00' }}</td>
                                <td>{{ $row['kpi_delai'] ?? '0.00' }}</td>
                                <td>{{ $row['kpi_risque'] ?? '0.00' }}</td>
                                <td>{{ $row['kpi_conformite'] ?? '0.00' }}</td>
                                <td>{{ $row['kpi_global'] ?? '0.00' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="muted">Aucun KPI disponible pour ce service.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @include('workspace.monitoring.partials.reporting-table-header', [
                    'directionLabel' => $directionLabel,
                    'directionResponsable' => $directionResponsable,
                    'serviceLabel' => $serviceLabel,
                    'serviceResponsable' => $serviceResponsable,
                    'tableTitle' => 'Risques du service',
                    'periodLabel' => $servicePeriodLabel,
                    'generatedAtLabel' => $generatedAtLabel,
                ])

                <table class="compact">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Risque</th>
                            <th>Niveau</th>
                            <th>Impact</th>
                            <th>Solution</th>
                            <th>Responsable</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($serviceRiskRows as $row)
                            <tr>
                                <td>{{ $row['action'] ?? '-' }}</td>
                                <td>{{ $row['risque_identifie'] ?? '-' }}</td>
                                <td>{{ $row['niveau_risque'] ?? '-' }}</td>
                                <td>{{ $row['kpi_risque'] ?? '0.00' }}</td>
                                <td>{{ $row['mesure_mitigation'] ?? '-' }}</td>
                                <td>{{ $row['rmo'] ?? $row['responsable'] ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="muted">Aucun risque identifié pour ce service.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @include('workspace.monitoring.partials.reporting-table-header', [
                    'directionLabel' => $directionLabel,
                    'directionResponsable' => $directionResponsable,
                    'serviceLabel' => $serviceLabel,
                    'serviceResponsable' => $serviceResponsable,
                    'tableTitle' => 'Performance KPI par RMO',
                    'periodLabel' => $servicePeriodLabel,
                    'generatedAtLabel' => $generatedAtLabel,
                ])

                <table class="compact">
                    <thead>
                        <tr>
                            <th>RMO</th>
                            <th>Nombre d’actions</th>
                            <th>Performance moyenne (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($serviceRmoRows as $row)
                            <tr>
                                <td>{{ $row['rmo'] }}</td>
                                <td>{{ $row['total'] }}</td>
                                <td>{{ number_format((float) $row['performance'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="muted">Aucune performance RMO disponible pour ce service.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @include('workspace.monitoring.partials.reporting-table-header', [
                    'directionLabel' => $directionLabel,
                    'directionResponsable' => $directionResponsable,
                    'serviceLabel' => $serviceLabel,
                    'serviceResponsable' => $serviceResponsable,
                    'tableTitle' => 'Suivi des justificatifs',
                    'periodLabel' => $servicePeriodLabel,
                    'generatedAtLabel' => $generatedAtLabel,
                ])

                <table class="compact">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>RMO</th>
                            <th>Justificatif</th>
                            <th>Statut validation</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($serviceJustificatifRows as $row)
                            <tr>
                                <td>{{ $row['action'] }}</td>
                                <td>{{ $row['rmo'] }}</td>
                                <td>{{ $row['justificatif'] }}</td>
                                <td>{{ $row['statut'] }}</td>
                                <td>{{ $row['date'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">Aucun justificatif disponible pour ce service.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @empty
            <div class="{{ $sectionClass }}">
                @if ($isPdf)
                    <span class="section-kicker">Service</span><h2>Aucun service disponible</h2>
                @else
                    <div class="blue-band">Aucun service disponible</div>
                @endif
                <p class="muted">Aucun service n’est rattaché à cette direction dans le périmètre du rapport.</p>
            </div>
        @endforelse
    @empty
        <div class="{{ $sectionClass }}">
            @if ($isPdf)
                <span class="section-kicker">Rapport</span><h2>Aucune direction disponible</h2>
            @else
                <div class="blue-band">Aucune direction disponible</div>
            @endif
            <p class="muted">Aucune donnée direction/service disponible pour ce rapport.</p>
        </div>
    @endforelse
@endif

@if (($templateBlocks['include_alerts'] ?? true) === true)
    <div class="{{ $sectionClass }}">
        @if ($isPdf)
            <span class="section-kicker">Tableau 6</span><h2>Alertes KPI sous seuil</h2>
        @else
            <div class="blue-band">Tableau 6 : Alertes KPI sous seuil</div>
        @endif

        @include('workspace.monitoring.partials.reporting-table-header', [
            'scopeLabel' => 'Périmètre',
            'scopeValue' => 'Consolidé ANBG',
            'tableTitle' => 'Alertes KPI sous seuil',
            'periodLabel' => $defaultPeriodLabel,
            'generatedAtLabel' => $generatedAtLabel,
        ])

        <table>
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Indicateur</th>
                    <th>Valeur</th>
                    <th>Seuil</th>
                    <th>Statut</th>
                    <th>Action corrective</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($alertRows as $row)
                    <tr>
                        <td>{{ $row['action'] }}</td>
                        <td>{{ $row['indicateur'] }}</td>
                        <td>{{ $row['valeur'] }}</td>
                        <td>{{ $row['seuil'] }}</td>
                        <td>{{ $row['statut'] }}</td>
                        <td>{{ $row['correctif'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Aucune alerte disponible.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endif
