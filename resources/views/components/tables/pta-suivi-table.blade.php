@props([
    'groups' => [],
    'exportMode' => 'web',
])

@php
    $isPdf = (string) $exportMode === 'pdf';
    $isInteractive = ! $isPdf && (string) $exportMode !== 'readonly';
    $statusStyle = static function (string $type, string $value): string {
        $map = [
            'suivi' => [
                'a_parametrer' => 'background:#6b7280;color:#fff;',
                'non_demarre' => 'background:#e5e7eb;color:#111827;',
                'en_cours' => 'background:#3996d3;color:#fff;',
                'validation_chef' => 'background:#9333ea;color:#fff;',
                'validation_controleur' => 'background:#4f46e5;color:#fff;',
                'cloture' => 'background:#00b050;color:#fff;',
            ],
            'delai' => [
                'dans_les_delais' => 'background:#00b050;color:#fff;',
                'hors_delai' => 'background:#f97316;color:#111827;',
            ],
            'alerte' => [
                'aucune_alerte' => 'background:#d9ead3;color:#14532d;',
                'echeance_proche' => 'background:#fff200;color:#111827;',
                'critique' => 'background:#f9b13c;color:#111827;',
                'en_retard' => 'background:#ff0000;color:#fff;',
                'cloturee' => 'background:#00b050;color:#fff;',
                'a_parametrer' => 'background:#6b7280;color:#fff;',
            ],
        ];

        return $map[$type][$value] ?? 'background:#e5e7eb;color:#111827;';
    };
@endphp

<div class="pta-suivi-table-wrap">
    <table class="pta-suivi-table">
        <tbody>
            @forelse ($groups as $pasGroup)
                <tr class="pta-pas-row">
                    <td class="pta-pas-code" rowspan="2">{{ $pasGroup['code'] ?? 'PAS' }}</td>
                    <td colspan="7" class="pta-pas-label">AXE STRATEGIQUE</td>
                    <td colspan="4" class="pta-pas-value">{{ $pasGroup['axes'][0]['label'] ?? '-' }}</td>
                    <td colspan="3" class="pta-pas-rate">{{ number_format((float) ($pasGroup['performance'] ?? 0), 0) }}%</td>
                </tr>
                <tr class="pta-strategy-row">
                    <td colspan="7" class="pta-strategy-label">Objectif strategique</td>
                    <td colspan="4" class="pta-strategy-value">{{ $pasGroup['axes'][0]['objectifs'][0]['label'] ?? '-' }}</td>
                    <td colspan="3" class="pta-strategy-rate">{{ number_format((float) ($pasGroup['axes'][0]['objectifs'][0]['performance'] ?? 0), 0) }}%</td>
                </tr>

                @foreach (($pasGroup['axes'] ?? []) as $axisGroup)
                    @foreach (($axisGroup['objectifs'] ?? []) as $strategicGroup)
                        @foreach (($strategicGroup['objectifs_operationnels'] ?? []) as $objectiveGroup)
                            <tr class="pta-objective-row">
                                <td class="pta-objective-number">{{ $loop->iteration }}</td>
                                <td colspan="7" class="pta-objective-title">Objectif operationnel</td>
                                <td colspan="4" class="pta-objective-value">{{ $objectiveGroup['label'] ?? '-' }}</td>
                                <td colspan="3" class="pta-objective-rate">{{ number_format((float) ($objectiveGroup['performance'] ?? 0), 0) }}%</td>
                            </tr>
                            <tr class="pta-header-row">
                                <th>N</th>
                                <th>Actions</th>
                                <th>Indicateurs de mesure</th>
                                <th>Responsable</th>
                                <th>Ratio</th>
                                <th>Taux de realisation (%)</th>
                                <th>Cible</th>
                                <th>Performance en fonction de la cible</th>
                                <th>Ecart</th>
                                <th>Echeance</th>
                                <th>Retard</th>
                                <th>Statut de suivi</th>
                                <th>Statut delai</th>
                                <th>Alerte echeance</th>
                                <th>Observations</th>
                            </tr>
                            @forelse (($objectiveGroup['actions'] ?? []) as $row)
                                <tr class="pta-action-row">
                                    <td class="pta-center">{{ $loop->iteration }}</td>
                                    <td class="pta-action-cell">
                                        @if (! $isInteractive)
                                            {{ $row['libelle'] ?? '-' }}
                                        @else
                                            <button
                                                type="button"
                                                class="pta-action-link"
                                                data-pta-action-open
                                                data-url="{{ $row['details_url'] ?? '#' }}"
                                            >
                                                {{ $row['libelle'] ?? '-' }}
                                            </button>
                                        @endif
                                    </td>
                                    <td>{{ $row['indicateur'] ?? '-' }}</td>
                                    <td class="pta-responsable">{{ $row['responsable'] ?? '-' }}</td>
                                    <td class="pta-center">{{ $row['ratio'] ?? '-' }}</td>
                                    <td class="pta-center">{{ $row['taux_realisation_label'] ?? '-' }}</td>
                                    <td>{{ $row['cible'] ?? '-' }}</td>
                                    <td class="pta-center">{{ $row['performance_label'] ?? '-' }}</td>
                                    <td class="pta-center">{{ $row['ecart_label'] ?? '-' }}</td>
                                    <td class="pta-center">{{ $row['echeance_label'] ?? '-' }}</td>
                                    <td class="pta-center">{{ $row['retard_label'] ?? '-' }}</td>
                                    <td class="pta-status-cell" style="{{ $statusStyle('suivi', (string) ($row['statut_suivi'] ?? '')) }}">{{ $row['statut_suivi_label'] ?? '-' }}</td>
                                    <td class="pta-status-cell" style="{{ $statusStyle('delai', (string) ($row['statut_delai'] ?? '')) }}">{{ $row['statut_delai_label'] ?? '-' }}</td>
                                    <td class="pta-status-cell" style="{{ $statusStyle('alerte', (string) ($row['alerte_echeance'] ?? '')) }}">{{ $row['alerte_echeance_label'] ?? '-' }}</td>
                                    <td class="pta-observation">{{ $row['observations'] ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="15" class="pta-empty">Aucune action rattachee a cet objectif operationnel.</td>
                                </tr>
                            @endforelse
                        @endforeach
                    @endforeach
                @endforeach
            @empty
                <tr>
                    <td class="pta-empty">Aucune action PTA ne correspond aux filtres actifs.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
