@props([
    'groups' => [],
    'rmoOptions' => [],
    'exportMode' => 'web',
])

@php
    $isPdf = (string) $exportMode === 'pdf';
    $isInteractive = ! $isPdf && (string) $exportMode !== 'readonly';
    $cellPreviewMode = $isInteractive ? 'readonly' : $exportMode;
    $tableColumnCount = $isInteractive ? 19 : 18;
    $pasLabelColspan = $isInteractive ? 15 : 14;
    $hierarchyValueColspan = $isInteractive ? 8 : 7;
    $actionCellStyle = 'background:#f8fafc;color:#111827;';
    $subActionCellStyle = 'background:#f1f5f9;color:#334155;';
    $previewUrl = static function (array $metricRow, array $row): string {
        return (string) ($metricRow['preview_url'] ?? $metricRow['details_url'] ?? $row['preview_url'] ?? $row['details_url'] ?? '#');
    };
    $inlineValue = static function (array $metricRow, string $key): string {
        return (string) data_get($metricRow, 'inline_values.'.$key, '');
    };
    $urlWithCurrentQuery = static function (string $url): string {
        $query = request()->query();

        if ($url === '' || $query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    };
@endphp

<div class="pta-suivi-table-wrap">
    <table class="pta-suivi-table">
        <colgroup>
            <col class="pta-col-number">
            <col class="pta-col-action">
            <col class="pta-col-sub-action">
            <col class="pta-col-indicator">
            <col class="pta-col-responsable">
            <col class="pta-col-ratio">
            <col class="pta-col-threshold">
            <col class="pta-col-realized">
            <col class="pta-col-rate">
            <col class="pta-col-performance">
            <col class="pta-col-gap">
            <col class="pta-col-deadline">
            <col class="pta-col-delay">
            <col class="pta-col-status">
            <col class="pta-col-status">
            <col class="pta-col-status">
            <col class="pta-col-proof">
            <col class="pta-col-observation">
            @if ($isInteractive)
                <col class="pta-col-row-actions">
            @endif
        </colgroup>
        <tbody>
            @forelse ($groups as $pasGroup)
                <tr class="pta-pas-row">
                    <td class="pta-pas-code">{{ $pasGroup['code'] ?? 'PAS' }}</td>
                    <td colspan="{{ $pasLabelColspan }}" class="pta-pas-label">{{ $pasGroup['label'] ?? 'PAS' }}</td>
                    <td colspan="3" class="pta-pas-rate">{{ $pasGroup['performance_label'] ?? number_format((float) ($pasGroup['performance'] ?? 0), 2).'%' }}</td>
                </tr>

                @foreach (($pasGroup['axes'] ?? []) as $axisGroup)
                    <x-pta.hierarchy-row
                        level="axis"
                        :label="$axisGroup['label'] ?? '-'"
                        :rate="$axisGroup['performance'] ?? null"
                        :code="$loop->iteration"
                        :colspan-value="$hierarchyValueColspan"
                    />
                    @foreach (($axisGroup['objectifs'] ?? []) as $strategicGroup)
                        <x-pta.hierarchy-row
                            level="strategic-objective"
                            :label="$strategicGroup['label'] ?? '-'"
                            :rate="$strategicGroup['performance'] ?? null"
                            :code="$loop->parent->iteration.'.'.$loop->iteration"
                            :colspan-value="$hierarchyValueColspan"
                        />
                        @foreach (($strategicGroup['objectifs_operationnels'] ?? []) as $objectiveGroup)
                            <x-pta.hierarchy-row
                                level="operational-objective"
                                :label="$objectiveGroup['label'] ?? '-'"
                                :rate="$objectiveGroup['performance'] ?? null"
                                :code="$loop->parent->parent->iteration.'.'.$loop->parent->iteration.'.'.$loop->iteration"
                                :colspan-value="$hierarchyValueColspan"
                            />
                            <tr class="pta-header-row">
                                <th>N</th>
                                <th>Actions</th>
                                <th>Sous-actions</th>
                                <th>Indicateurs de mesure</th>
                                <th>RMO</th>
                                <th>Ratio</th>
                                <th>Seuil</th>
                                <th>Realise</th>
                                <th>Taux (%)</th>
                                <th>Performance</th>
                                <th>Ecart</th>
                                <th>Echeance</th>
                                <th>Retard</th>
                                <th>Statut action</th>
                                <th>Statut de suivi</th>
                                <th>Statut delai</th>
                                <th>Preuve</th>
                                <th>Observations</th>
                                @if ($isInteractive)
                                    <th class="pta-row-actions-heading">Commandes</th>
                                @endif
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
                                        $canEditMetric = $isInteractive && (bool) (($metricRow['inline_editable'] ?? $row['inline_editable'] ?? false) === true);
                                        $canEditAction = $canEditMetric && ! $hasSubAction;
                                        $canEditActionHeader = $isInteractive && $hasSubAction && $loop->first && (bool) (($row['inline_editable'] ?? false) === true);
                                        $formId = 'pta-inline-'.$row['id'].'-'.($hasSubAction ? 'sa-'.$metricRow['id'] : 'action');
                                        $indicatorPanelId = 'pta-indicator-panel-'.$row['id'].'-'.($hasSubAction ? 'sa-'.$metricRow['id'] : 'action');
                                        $actionFormId = 'pta-inline-'.$row['id'].'-action-header';
                                        $deleteFormId = 'pta-delete-'.$row['id'].'-'.($hasSubAction ? 'sa-'.$metricRow['id'] : 'action');
                                        $parameterUrl = (string) ($metricRow['parameter_url'] ?? $row['parameter_url'] ?? '');
                                        $inlineUpdateUrl = $urlWithCurrentQuery((string) ($metricRow['inline_update_url'] ?? $row['inline_update_url'] ?? ''));
                                        $inlineDeleteUrl = $urlWithCurrentQuery((string) ($metricRow['inline_delete_url'] ?? $row['inline_delete_url'] ?? ''));
                                        $indicatorTypeOptions = (array) ($metricRow['indicator_type_options'] ?? $row['indicator_type_options'] ?? []);
                                        $indicatorTypeValue = $inlineValue($metricRow, 'type_indicateur') ?: 'non_quantitatif';
                                        $indicatorTypeLabel = (string) ($metricRow['type_indicateur_label'] ?? $indicatorTypeOptions[$indicatorTypeValue] ?? 'Type');
                                        $indicatorDisplay = trim((string) ($metricRow['indicateur'] ?? ''));
                                        $indicatorDisplay = $indicatorDisplay === '-' ? '' : $indicatorDisplay;
                                        $thresholdLabel = (string) ($metricRow['seuil_label'] ?? $metricRow['seuil'] ?? '-');
                                        $selectedRmoId = (int) ($inlineValue($metricRow, 'rmo_id') ?: 0);
                                        $quantityValue = trim($inlineValue($metricRow, 'quantite_a_realiser'));
                                        $unitValue = trim($inlineValue($metricRow, 'unite'));
                                        $quantityLabel = trim($quantityValue.' '.$unitValue);
                                        $deliverableValue = trim($inlineValue($metricRow, 'livrable_attendu'));
                                        $showQuantity = in_array($indicatorTypeValue, ['quantitatif', 'mixte'], true);
                                        $showDeliverable = in_array($indicatorTypeValue, ['non_quantitatif', 'mixte'], true);
                                        $actionIndicatorTypeOptions = (array) ($row['indicator_type_options'] ?? []);
                                        $actionIndicatorTypeValue = $inlineValue($row, 'type_indicateur') ?: 'non_quantitatif';
                                        $actionIndicatorTypeLabel = (string) ($row['type_indicateur_label'] ?? $actionIndicatorTypeOptions[$actionIndicatorTypeValue] ?? 'Type');
                                        $actionSelectedRmoId = (int) ($inlineValue($row, 'rmo_id') ?: 0);
                                        $actionIsConfigured = (bool) ($row['calcul_configured'] ?? false);
                                        $trackingUrl = (string) ($metricRow['action_url'] ?? $row['action_url'] ?? '#');
                                        $reportUrl = (string) ($metricRow['report_url'] ?? $row['report_url'] ?? '#');
                                        $canRequestReport = (bool) ($metricRow['can_request_report'] ?? $row['can_request_report'] ?? false);
                                    @endphp
                                    <tr id="pta-row-{{ $row['id'] }}-{{ $hasSubAction ? 'sa-'.$metricRow['id'] : 'action' }}" class="pta-action-row {{ $hasSubAction ? 'pta-sub-action-row' : 'pta-level-action' }}" data-pta-inline-row>
                                        @if ($loop->first)
                                            <td rowspan="{{ $rowspan }}" class="pta-center pta-action-index-cell pta-hierarchy-action-cell" style="{{ $actionCellStyle }}">
                                                <x-pta.preview-link :url="$actionPreviewUrl" :export-mode="$cellPreviewMode">{{ $actionNumber }}</x-pta.preview-link>
                                            </td>
                                            <td rowspan="{{ $rowspan }}" class="pta-action-cell pta-action-parent-cell pta-hierarchy-action-cell" style="{{ $actionCellStyle }}">
                                                @if ($canEditActionHeader)
                                                    <form id="{{ $actionFormId }}" method="POST" action="{{ $urlWithCurrentQuery((string) ($row['inline_update_url'] ?? '')) }}" class="pta-inline-hidden-form" data-pta-inline-form>
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="row_type" value="action">
                                                    </form>

                                                    <div class="pta-param-editor pta-action-header-editor" data-pta-param-editor data-pta-current-type="{{ $actionIndicatorTypeValue }}">
                                                        <button type="button" class="pta-param-trigger pta-action-config-trigger" data-pta-param-open>
                                                            <span class="pta-param-kicker">Action mère</span>
                                                            <strong>{{ $row['libelle'] ?? 'Action à renseigner' }}</strong>
                                                            <span class="pta-param-trigger-meta">
                                                                <span class="pta-param-chip">{{ $actionIndicatorTypeLabel }}</span>
                                                                <span class="pta-param-chip {{ $actionIsConfigured ? 'pta-param-chip-ready' : 'pta-param-chip-missing' }}">
                                                                    {{ $actionIsConfigured ? 'Paramétrée' : 'À compléter' }}
                                                                </span>
                                                            </span>
                                                            <span class="pta-param-trigger-details">Sélectionner pour paramétrer l’action directement.</span>
                                                        </button>

                                                        <div class="pta-param-panel pta-param-fields pta-action-config-panel" data-pta-param-fields hidden>
                                                            <label class="pta-param-field pta-param-field-full">
                                                                <span>Libellé de l’action</span>
                                                                <textarea form="{{ $actionFormId }}" name="libelle" rows="3" class="pta-inline-field pta-inline-textarea" aria-label="Action" placeholder="Action à renseigner" required maxlength="255" data-pta-cell-input>{{ $inlineValue($row, 'libelle') }}</textarea>
                                                            </label>
                                                            <label class="pta-param-field pta-param-field-full">
                                                                <span>Type d’indicateur</span>
                                                                <select form="{{ $actionFormId }}" name="type_indicateur" class="pta-inline-field pta-param-type-select" aria-label="Type d’indicateur de l’action" data-pta-type-input data-pta-cell-input>
                                                                    @foreach ($actionIndicatorTypeOptions as $value => $label)
                                                                        <option value="{{ $value }}" data-type-label="{{ $label }}" @selected($actionIndicatorTypeValue === (string) $value)>{{ $label }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </label>
                                                            <label class="pta-param-field pta-param-field-full">
                                                                <span>Indicateur de mesure</span>
                                                                <textarea form="{{ $actionFormId }}" name="indicateur" rows="2" class="pta-inline-field pta-inline-textarea" aria-label="Indicateur de l’action" placeholder="Indicateur à renseigner" maxlength="1000" data-pta-cell-input>{{ $inlineValue($row, 'indicateur') }}</textarea>
                                                            </label>
                                                            <div class="pta-param-grid" data-pta-type-field="quantitatif mixte">
                                                                <label class="pta-param-field">
                                                                    <span>Quantité cible</span>
                                                                    <input form="{{ $actionFormId }}" name="quantite_a_realiser" type="number" min="0" step="0.0001" value="{{ $inlineValue($row, 'quantite_a_realiser') }}" class="pta-inline-field" aria-label="Quantité cible de l’action" placeholder="Ex. 10" data-pta-cell-input>
                                                                </label>
                                                                <label class="pta-param-field">
                                                                    <span>Unité</span>
                                                                    <input form="{{ $actionFormId }}" name="unite" type="text" value="{{ $inlineValue($row, 'unite') }}" class="pta-inline-field" aria-label="Unité de l’action" placeholder="dossiers" maxlength="100" data-pta-cell-input>
                                                                </label>
                                                            </div>
                                                            <label class="pta-param-field pta-param-field-full" data-pta-type-field="non_quantitatif mixte">
                                                                <span>Livrable / résultat attendu</span>
                                                                <textarea form="{{ $actionFormId }}" name="livrable_attendu" rows="2" class="pta-inline-field pta-inline-textarea" aria-label="Livrable de l’action" placeholder="Livrable attendu" maxlength="1000" data-pta-cell-input>{{ $inlineValue($row, 'livrable_attendu') }}</textarea>
                                                            </label>
                                                            <div class="pta-param-grid">
                                                                <label class="pta-param-field">
                                                                    <span>Seuil (%)</span>
                                                                    <input form="{{ $actionFormId }}" name="seuil_minimum" type="number" min="0" max="100" step="0.01" value="{{ $inlineValue($row, 'seuil_minimum') }}" class="pta-inline-field" aria-label="Seuil de l’action" placeholder="80" data-pta-cell-input>
                                                                </label>
                                                                <label class="pta-param-field">
                                                                    <span>RMO</span>
                                                                    <select form="{{ $actionFormId }}" name="rmo_id" class="pta-inline-field" aria-label="RMO de l’action" data-pta-cell-input>
                                                                        <option value="">Non assigné</option>
                                                                        @foreach ($rmoOptions as $rmo)
                                                                            <option value="{{ $rmo['id'] }}" @selected($actionSelectedRmoId === (int) $rmo['id'])>{{ $rmo['label'] }}</option>
                                                                        @endforeach
                                                                    </select>
                                                                </label>
                                                            </div>
                                                            <div class="pta-deadline-locked-note">
                                                                <strong>Dates verrouillées</strong>
                                                                <span>Début : {{ $inlineValue($row, 'date_debut') ?: '-' }} · Fin : {{ $inlineValue($row, 'date_fin') ?: '-' }}</span>
                                                                <span>Toute nouvelle échéance passe par la demande de report.</span>
                                                            </div>
                                                            <label class="pta-param-field pta-param-field-full">
                                                                <span>Observations</span>
                                                                <textarea form="{{ $actionFormId }}" name="observations" rows="2" class="pta-inline-field pta-inline-textarea" aria-label="Observations de l’action" placeholder="Observation" maxlength="2000" data-pta-cell-input>{{ $inlineValue($row, 'observations') }}</textarea>
                                                            </label>
                                                            <div class="pta-action-config-actions">
                                                                <button form="{{ $actionFormId }}" class="pta-inline-save" type="submit" data-pta-save>Enregistrer l’action</button>
                                                                <button class="pta-inline-cancel" type="button" data-pta-param-cancel>Annuler</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @elseif ($canEditAction)
                                                    <textarea form="{{ $formId }}" name="libelle" rows="3" class="pta-inline-field pta-inline-textarea" aria-label="Action" placeholder="Action a renseigner" required maxlength="255" data-pta-cell-input>{{ $inlineValue($row, 'libelle') }}</textarea>
                                                @else
                                                    <x-pta.preview-link :url="$actionPreviewUrl" :export-mode="$cellPreviewMode" class="pta-action-link">{{ $row['libelle'] ?? '-' }}</x-pta.preview-link>
                                                @endif
                                            </td>
                                        @endif
                                        <td class="pta-sub-action-cell {{ $hasSubAction ? 'pta-hierarchy-sub-action-cell' : 'pta-hierarchy-action-cell' }}" style="{{ $hasSubAction ? $subActionCellStyle : $actionCellStyle }}">
                                            @if ($canEditMetric && $inlineUpdateUrl !== '')
                                                <form id="{{ $formId }}" method="POST" action="{{ $inlineUpdateUrl }}" class="pta-inline-hidden-form" data-pta-inline-form>
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="row_type" value="{{ $hasSubAction ? 'sous_action' : 'action' }}">
                                                    @if ($hasSubAction)
                                                        <input type="hidden" name="sous_action_id" value="{{ $metricRow['id'] ?? '' }}">
                                                    @else
                                                        <input type="hidden" name="libelle" value="{{ $inlineValue($row, 'libelle') }}">
                                                    @endif
                                                </form>
                                            @endif
                                            @if ($canEditMetric && $inlineDeleteUrl !== '')
                                                <form
                                                    id="{{ $deleteFormId }}"
                                                    method="POST"
                                                    action="{{ $inlineDeleteUrl }}"
                                                    class="pta-inline-hidden-form"
                                                    data-confirm-message="Supprimer cette {{ $hasSubAction ? 'sous-action' : 'action' }} du Suivi PTA ?"
                                                    data-confirm-tone="danger"
                                                    data-confirm-label="Supprimer"
                                                >
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="row_type" value="{{ $hasSubAction ? 'sous_action' : 'action' }}">
                                                    @if ($hasSubAction)
                                                        <input type="hidden" name="sous_action_id" value="{{ $metricRow['id'] ?? '' }}">
                                                    @endif
                                                </form>
                                            @endif
                                            @if ($canEditMetric && $hasSubAction)
                                                <div class="pta-inline-stack">
                                                    <span class="pta-sub-action-number">{{ $metricRow['numero'] ?? $loop->iteration }}.</span>
                                                    <textarea form="{{ $formId }}" name="libelle" rows="3" class="pta-inline-field pta-inline-textarea" aria-label="Sous-action" placeholder="Sous-action a renseigner" required maxlength="255" data-pta-cell-input>{{ $inlineValue($metricRow, 'libelle') }}</textarea>
                                                </div>
                                            @elseif ($canEditMetric)
                                                <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">-</x-pta.preview-link>
                                            @else
                                                <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">
                                                    @if ($hasSubAction)
                                                        <span class="pta-sub-action-number">{{ $metricRow['numero'] ?? $loop->iteration }}.</span>
                                                        {{ $metricRow['libelle'] ?? '-' }}
                                                    @else
                                                        -
                                                    @endif
                                                </x-pta.preview-link>
                                            @endif
                                        </td>
                                        <td class="pta-indicator-cell {{ $canEditMetric ? 'is-editable' : '' }}" @if ($canEditMetric) data-pta-indicator-cell @endif>
                                            @if ($canEditMetric)
                                                <div class="pta-param-editor" data-pta-param-editor data-pta-current-type="{{ $indicatorTypeValue }}">
                                                    <button type="button" class="pta-param-trigger" data-pta-param-open aria-expanded="false" aria-controls="{{ $indicatorPanelId }}">
                                                        <span class="pta-param-trigger-heading">
                                                            <span class="pta-param-kicker">Indicateur de mesure</span>
                                                            <span class="pta-param-edit-affordance">{{ $indicatorDisplay === '' ? 'Parametrer' : 'Modifier' }}</span>
                                                        </span>
                                                        <strong>{{ $indicatorDisplay !== '' ? $indicatorDisplay : 'A renseigner' }}</strong>
                                                        <span class="pta-param-trigger-meta">
                                                            <span class="pta-param-chip" data-pta-current-type-label>{{ $indicatorTypeLabel }}</span>
                                                        </span>
                                                        <span class="pta-param-trigger-details">
                                                            @if ($showQuantity)
                                                                <span>Quantite a realiser : {{ $quantityLabel !== '' ? $quantityLabel : 'A renseigner' }}</span>
                                                            @endif
                                                            @if ($showDeliverable)
                                                                <span>Livrable attendu : {{ $deliverableValue !== '' ? $deliverableValue : 'A renseigner' }}</span>
                                                            @endif
                                                        </span>
                                                    </button>

                                                    <div id="{{ $indicatorPanelId }}" class="pta-param-panel pta-param-fields" data-pta-param-fields hidden>
                                                        <label class="pta-param-field pta-param-field-full">
                                                            <span>Type d'indicateur</span>
                                                            <select form="{{ $formId }}" name="type_indicateur" class="pta-inline-field pta-param-type-select" aria-label="Type d'indicateur" required data-pta-type-input data-pta-cell-input>
                                                                @foreach ($indicatorTypeOptions as $value => $label)
                                                                    <option value="{{ $value }}" data-type-label="{{ $label }}" @selected($indicatorTypeValue === (string) $value)>{{ $label }}</option>
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                        <label class="pta-param-field pta-param-field-full">
                                                            <span>Indicateur mesure</span>
                                                            <textarea form="{{ $formId }}" name="indicateur" rows="2" class="pta-inline-field pta-inline-textarea" aria-label="Indicateur" placeholder="Indicateur a renseigner" maxlength="1000" data-pta-cell-input>{{ $inlineValue($metricRow, 'indicateur') }}</textarea>
                                                        </label>
                                                        <div class="pta-param-grid" data-pta-type-field="quantitatif mixte">
                                                            <label class="pta-param-field">
                                                                <span>Quantite a realiser</span>
                                                                <input form="{{ $formId }}" name="quantite_a_realiser" type="number" min="0" step="0.0001" value="{{ $inlineValue($metricRow, 'quantite_a_realiser') }}" class="pta-inline-field" aria-label="Quantite a realiser" placeholder="Ex. 10" data-pta-cell-input>
                                                            </label>
                                                            <label class="pta-param-field">
                                                                <span>Unite</span>
                                                                <input form="{{ $formId }}" name="unite" type="text" value="{{ $inlineValue($metricRow, 'unite') }}" class="pta-inline-field" aria-label="Unite" placeholder="dossiers" maxlength="100" data-pta-cell-input>
                                                            </label>
                                                        </div>
                                                        <label class="pta-param-field pta-param-field-full" data-pta-type-field="non_quantitatif mixte">
                                                            <span>Livrable / resultat attendu</span>
                                                            <textarea form="{{ $formId }}" name="livrable_attendu" rows="2" class="pta-inline-field pta-inline-textarea" aria-label="Livrable ou resultat attendu" placeholder="Livrable attendu" maxlength="1000" data-pta-cell-input>{{ $inlineValue($metricRow, 'livrable_attendu') }}</textarea>
                                                        </label>
                                                    </div>
                                                </div>
                                            @else
                                                <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">
                                                    <span class="pta-param-readonly">
                                                        <span class="pta-param-readonly-title">{{ $indicatorDisplay !== '' ? $indicatorDisplay : 'A renseigner' }}</span>
                                                        <span class="pta-param-trigger-meta">
                                                            <span class="pta-param-chip">{{ $indicatorTypeLabel }}</span>
                                                        </span>
                                                        <span class="pta-param-trigger-details">
                                                            @if ($showQuantity)
                                                                <span>Quantite a realiser : {{ $quantityLabel !== '' ? $quantityLabel : 'A renseigner' }}</span>
                                                            @endif
                                                            @if ($showDeliverable)
                                                                <span>Livrable attendu : {{ $deliverableValue !== '' ? $deliverableValue : 'A renseigner' }}</span>
                                                            @endif
                                                        </span>
                                                    </span>
                                                </x-pta.preview-link>
                                            @endif
                                        </td>
                                        <td class="pta-responsable">
                                            @if ($canEditMetric)
                                                <select form="{{ $formId }}" name="rmo_id" class="pta-inline-field" aria-label="RMO" data-pta-cell-input>
                                                    <option value="">Non assigne</option>
                                                    @foreach ($rmoOptions as $rmo)
                                                        <option value="{{ $rmo['id'] }}" @selected($selectedRmoId === (int) $rmo['id'])>{{ $rmo['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">{{ $metricRow['responsable'] ?? '-' }}</x-pta.preview-link>
                                            @endif
                                        </td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">{{ $metricRow['ratio'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-threshold-cell">
                                            @if ($canEditMetric)
                                                <label class="pta-threshold-card pta-editable-threshold" data-pta-edit-cell>
                                                    <span class="pta-threshold-label">Seuil (%)</span>
                                                    <span class="pta-threshold-input-wrap">
                                                        <input form="{{ $formId }}" name="seuil_minimum" type="number" min="0" max="100" step="0.01" value="{{ $inlineValue($metricRow, 'seuil_minimum') }}" class="pta-inline-field" aria-label="Seuil en pourcentage" placeholder="80" data-pta-cell-input>
                                                        <span>%</span>
                                                    </span>
                                                </label>
                                            @else
                                                <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">
                                                <span class="pta-threshold-card">
                                                    <span class="pta-threshold-label">Seuil</span>
                                                    <strong>{{ $thresholdLabel }}</strong>
                                                </span>
                                                </x-pta.preview-link>
                                            @endif
                                        </td>
                                        <td><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">{{ $metricRow['realise'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">{{ $metricRow['taux_realisation_label'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">{{ $metricRow['performance_label'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">{{ $metricRow['ecart_label'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-center">
                                            <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">{{ $metricRow['echeance_label'] ?? '-' }}</x-pta.preview-link>
                                        </td>
                                        <td class="pta-center"><x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">{{ $metricRow['retard_label'] ?? '-' }}</x-pta.preview-link></td>
                                        <td class="pta-status-cell">
                                            <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">
                                                <x-pta.status-badge type="action" :status="$metricRow['statut_action'] ?? 'en_cours'" :label="$metricRow['statut_action_label'] ?? null" />
                                            </x-pta.preview-link>
                                        </td>
                                        <td class="pta-status-cell">
                                            <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">
                                                <x-pta.status-badge type="suivi" :status="$metricRow['statut_suivi'] ?? 'en_cours'" :label="$metricRow['statut_suivi_label'] ?? null" />
                                            </x-pta.preview-link>
                                        </td>
                                        <td class="pta-status-cell">
                                            <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">
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
                                        <td class="pta-observation">
                                            @if ($canEditMetric)
                                                <div class="pta-inline-stack">
                                                    <textarea form="{{ $formId }}" name="observations" rows="2" class="pta-inline-field pta-inline-textarea" aria-label="Observations" placeholder="Observation a renseigner" maxlength="2000" data-pta-cell-input>{{ $inlineValue($metricRow, 'observations') }}</textarea>
                                                </div>
                                            @else
                                                <x-pta.preview-link :url="$previewUrl($metricRow, $row)" :export-mode="$cellPreviewMode">{{ $metricRow['observations'] ?? '-' }}</x-pta.preview-link>
                                            @endif
                                        </td>
                                        @if ($isInteractive)
                                            <td class="pta-row-actions">
                                                <div class="pta-row-actions-stack">
                                                    @if ($canEditMetric && $inlineUpdateUrl !== '')
                                                        <button form="{{ $formId }}" class="pta-inline-save" type="submit" data-pta-save>Enregistrer</button>
                                                        <span class="pta-inline-save-state" data-pta-save-state aria-live="polite">Aucune modification</span>
                                                    @endif
                                                    <a class="pta-inline-open" href="{{ $trackingUrl }}">Faire le suivi</a>
                                                    @if ($canRequestReport)
                                                        <a class="pta-inline-report" href="{{ $reportUrl }}">Demander un report</a>
                                                    @else
                                                        <span class="pta-inline-report is-disabled" aria-disabled="true">Report indisponible</span>
                                                    @endif
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            @empty
                                <tr>
                                    <td colspan="{{ $tableColumnCount }}" class="pta-empty">Aucune action rattachee a cet objectif operationnel.</td>
                                </tr>
                            @endforelse
                        @endforeach
                    @endforeach
                @endforeach
            @empty
                <tr>
                    <td colspan="{{ $tableColumnCount }}" class="pta-empty">Aucune action PTA ne correspond aux filtres actifs.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
