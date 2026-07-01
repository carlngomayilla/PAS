@props([
    'groups' => [],
    'exportMode' => 'web',
])

@php
    $isPdf = (string) $exportMode === 'pdf';
    $isInteractive = ! $isPdf && (string) $exportMode !== 'readonly';
@endphp

<div class="pta-suivi-table-wrap">
    <table class="pta-suivi-table">
        <tbody>
            @forelse ($groups as $pasGroup)
                <tr class="pta-pas-row">
                    <td class="pta-pas-code">{{ $pasGroup['code'] ?? 'PAS' }}</td>
                    <td colspan="14" class="pta-pas-label">{{ $pasGroup['label'] ?? 'PAS' }}</td>
                    <td colspan="3" class="pta-pas-rate">{{ $pasGroup['performance_label'] ?? number_format((float) ($pasGroup['performance'] ?? 0), 2).'%' }}</td>
                </tr>

                @foreach (($pasGroup['axes'] ?? []) as $axisGroup)
                    <x-pta.hierarchy-row
                        level="axis"
                        :label="$axisGroup['label'] ?? '-'"
                        :rate="$axisGroup['performance'] ?? null"
                        :code="$loop->iteration"
                    />
                    @foreach (($axisGroup['objectifs'] ?? []) as $strategicGroup)
                        <x-pta.hierarchy-row
                            level="strategic-objective"
                            :label="$strategicGroup['label'] ?? '-'"
                            :rate="$strategicGroup['performance'] ?? null"
                            :code="$loop->parent->iteration.'.'.$loop->iteration"
                        />
                        @foreach (($strategicGroup['objectifs_operationnels'] ?? []) as $objectiveGroup)
                            <x-pta.hierarchy-row
                                level="operational-objective"
                                :label="$objectiveGroup['label'] ?? '-'"
                                :rate="$objectiveGroup['performance'] ?? null"
                                :code="$loop->parent->parent->iteration.'.'.$loop->parent->iteration.'.'.$loop->iteration"
                            />
                            <tr class="pta-header-row">
                                <th>N</th>
                                <th>Actions</th>
                                <th>Sous-actions</th>
                                <th>Indicateurs de mesure</th>
                                <th>Responsable</th>
                                <th>Ratio</th>
                                <th>Cible</th>
                                <th>Realise</th>
                                <th>Taux (%)</th>
                                <th>Performance en fonction de la cible</th>
                                <th>Ecart</th>
                                <th>Echeance</th>
                                <th>Retard</th>
                                <th>Statut action</th>
                                <th>Statut de suivi</th>
                                <th>Statut delai</th>
                                <th>Preuve</th>
                                <th>Observations</th>
                            </tr>
                            @forelse (($objectiveGroup['actions'] ?? []) as $row)
                                @php
                                    $actionNumber = $loop->iteration;
                                    $subActions = collect($row['sous_actions'] ?? []);
                                    $detailRows = $subActions->isNotEmpty() ? $subActions : collect([null]);
                                    $rowspan = $detailRows->count();
                                @endphp
                                @foreach ($detailRows as $detailRow)
                                    @php
                                        $hasSubAction = is_array($detailRow);
                                        $metricRow = $hasSubAction ? $detailRow : $row;
                                    @endphp
                                    <tr class="pta-action-row {{ $hasSubAction ? 'pta-sub-action-row' : 'pta-level-action' }}">
                                        @if ($loop->first)
                                            <td rowspan="{{ $rowspan }}" class="pta-center pta-action-index-cell">{{ $actionNumber }}</td>
                                            <td rowspan="{{ $rowspan }}" class="pta-action-cell pta-action-parent-cell">
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
                                        @endif
                                        <td class="pta-sub-action-cell">
                                            @if ($hasSubAction)
                                                <span class="pta-sub-action-number">{{ $metricRow['numero'] ?? $loop->iteration }}.</span>
                                                {{ $metricRow['libelle'] ?? '-' }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>{{ $metricRow['indicateur'] ?? '-' }}</td>
                                        <td class="pta-responsable">{{ $metricRow['responsable'] ?? '-' }}</td>
                                        <td class="pta-center">{{ $metricRow['ratio'] ?? '-' }}</td>
                                        <td>{{ $metricRow['cible'] ?? '-' }}</td>
                                        <td>{{ $metricRow['realise'] ?? '-' }}</td>
                                        <td class="pta-center">{{ $metricRow['taux_realisation_label'] ?? '-' }}</td>
                                        <td class="pta-center">{{ $metricRow['performance_label'] ?? '-' }}</td>
                                        <td class="pta-center">{{ $metricRow['ecart_label'] ?? '-' }}</td>
                                        <td class="pta-center">{{ $metricRow['echeance_label'] ?? '-' }}</td>
                                        <td class="pta-center">{{ $metricRow['retard_label'] ?? '-' }}</td>
                                        <td class="pta-status-cell">
                                            <x-pta.status-badge type="action" :status="$metricRow['statut_action'] ?? 'en_cours'" :label="$metricRow['statut_action_label'] ?? null" />
                                        </td>
                                        <td class="pta-status-cell">
                                            <x-pta.status-badge type="suivi" :status="$metricRow['statut_suivi'] ?? 'en_cours'" :label="$metricRow['statut_suivi_label'] ?? null" />
                                        </td>
                                        <td class="pta-status-cell">
                                            <x-pta.status-badge type="delai" :status="$metricRow['statut_delai'] ?? 'dans_les_delais'" :label="$metricRow['statut_delai_label'] ?? null" />
                                        </td>
                                        <td class="pta-center">
                                            <x-pta.proof-button
                                                :has-proof="$metricRow['has_preuve'] ?? false"
                                                :count="$metricRow['preuve_count'] ?? 0"
                                                :url="$row['details_url'] ?? '#'"
                                                :export-mode="$exportMode"
                                            />
                                        </td>
                                        <td class="pta-observation">{{ $metricRow['observations'] ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            @empty
                                <tr>
                                    <td colspan="18" class="pta-empty">Aucune action rattachee a cet objectif operationnel.</td>
                                </tr>
                            @endforelse
                        @endforeach
                    @endforeach
                @endforeach
            @empty
                <tr>
                    <td colspan="18" class="pta-empty">Aucune action PTA ne correspond aux filtres actifs.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
