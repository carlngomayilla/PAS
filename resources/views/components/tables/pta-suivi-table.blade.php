@props([
    'groups' => [],
    'exportMode' => 'web',
])

@php
    $isPdf = (string) $exportMode === 'pdf';
    $isInteractive = ! $isPdf && (string) $exportMode !== 'readonly';
    $actionCellStyle = 'background:#f8fafc;color:#111827;';
    $subActionCellStyle = 'background:#f1f5f9;color:#334155;';
    $previewUrl = static function (array $metricRow, array $row): string {
        return (string) ($metricRow['preview_url'] ?? $metricRow['details_url'] ?? $row['preview_url'] ?? $row['details_url'] ?? '#');
    };
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
                                        $actionPreviewUrl = (string) ($row['preview_url'] ?? $row['details_url'] ?? '#');
                                        $parameterUrl = (string) ($metricRow['parameter_url'] ?? $row['parameter_url'] ?? '');
                                        $needsParameter = (bool) (($metricRow['calcul_configured'] ?? $row['calcul_configured'] ?? true) === false);
                                    @endphp
                                    <tr class="pta-action-row {{ $hasSubAction ? 'pta-sub-action-row' : 'pta-level-action' }}">
                                        @if ($loop->first)
                                            <td rowspan="{{ $rowspan }}" class="pta-center pta-action-index-cell pta-hierarchy-action-cell" style="{{ $actionCellStyle }}">
                                                <x-pta.preview-link :url="$actionPreviewUrl" :export-mode="$exportMode">{{ $actionNumber }}</x-pta.preview-link>
                                            </td>
                                            <td rowspan="{{ $rowspan }}" class="pta-action-cell pta-action-parent-cell pta-hierarchy-action-cell" style="{{ $actionCellStyle }}">
                                                <x-pta.preview-link :url="$actionPreviewUrl" :export-mode="$exportMode" class="pta-action-link">{{ $row['libelle'] ?? '-' }}</x-pta.preview-link>
                                            </td>
                                        @endif
                                        <td class="pta-sub-action-cell {{ $hasSubAction ? 'pta-hierarchy-sub-action-cell' : 'pta-hierarchy-action-cell' }}" style="{{ $hasSubAction ? $subActionCellStyle : $actionCellStyle }}">
                                            <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">
                                                @if ($hasSubAction)
                                                    <span class="pta-sub-action-number">{{ $metricRow['numero'] ?? $loop->iteration }}.</span>
                                                    {{ $metricRow['libelle'] ?? '-' }}
                                                @else
                                                    -
                                                @endif
                                            </x-pta.preview-link>
                                        </td>
                                        <td><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['indicateur'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-responsable"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['responsable'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['ratio'] ?? '-' }}</x-pta.preview-link></td>
                                        <td>
                                            <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['cible'] ?? '-' }}</x-pta.preview-link>
                                            @if ($needsParameter && $parameterUrl !== '' && $isInteractive)
                                                <a class="pta-parameter-pill" href="{{ $parameterUrl }}">Parametrer</a>
                                            @endif
                                        </td>
                                        <td><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['realise'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['taux_realisation_label'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['performance_label'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['ecart_label'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['echeance_label'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['retard_label'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-status-cell">
                                            <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">
                                                <x-pta.status-badge type="action" :status="$metricRow['statut_action'] ?? 'en_cours'" :label="$metricRow['statut_action_label'] ?? null" />
                                            </x-pta.preview-link>
                                        </td>
                                        <td class="pta-status-cell">
                                            <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">
                                                <x-pta.status-badge type="suivi" :status="$metricRow['statut_suivi'] ?? 'en_cours'" :label="$metricRow['statut_suivi_label'] ?? null" />
                                            </x-pta.preview-link>
                                        </td>
                                        <td class="pta-status-cell">
                                            <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">
                                                <x-pta.status-badge type="delai" :status="$metricRow['statut_delai'] ?? 'dans_les_delais'" :label="$metricRow['statut_delai_label'] ?? null" />
                                            </x-pta.preview-link>
                                        </td>
                                        <td class="pta-center">
                                            <x-pta.proof-button
                                                :has-proof="$metricRow['has_preuve'] ?? false"
                                                :count="$metricRow['preuve_count'] ?? 0"
                                                :preview-url="$metricRow['proof_preview_url'] ?? $row['proof_preview_url'] ?? '#'"
                                                :download-url="$metricRow['proof_download_url'] ?? $row['proof_download_url'] ?? '#'"
                                                :title="$metricRow['proof_title'] ?? $row['proof_title'] ?? 'Piece justificative'"
                                                :subtitle="$metricRow['proof_subtitle'] ?? $row['proof_subtitle'] ?? 'Preuve de traitement'"
                                                :mime="$metricRow['proof_mime'] ?? $row['proof_mime'] ?? ''"
                                                :export-mode="$exportMode"
                                            />
                                        </td>
                                        <td class="pta-observation"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$exportMode">{{ $metricRow['observations'] ?? '-' }}</x-pta.preview-link></td>
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
