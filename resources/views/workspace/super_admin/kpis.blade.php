@extends('layouts.workspace')

@section('title', 'KPI et statistiques')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">KPI et statistiques</h1>
                <p class="mt-2 text-slate-600">Registre des indicateurs consolides utilises par le reporting et les dashboards. Le calcul devient pilotable sans code via des presets de formule, des sources multiples, des poids et une cible optionnelle.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.calculation.edit') }}">Politique de calcul</a>
                <a class="btn btn-secondary" href="{{ route('workspace.reporting') }}">Reporting</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">KPI visibles</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['visible'] }}</p>
            <p class="mt-2 text-sm text-slate-600">Indicateurs exposes dans les syntheses consolidees.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">KPI registres</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['total'] }}</p>
            <p class="mt-2 text-sm text-slate-600">Delai, performance, conformite, qualite, risque, global et progression.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Profils cibles distincts</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['profiles'] }}</p>
            <p class="mt-2 text-sm text-slate-600">Si vide pour un KPI, il reste visible pour tous les profils.</p>
        </article>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" action="{{ route('workspace.super-admin.kpis.update') }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Registre pilote</h2>
                <p class="form-section-subtitle">Chaque KPI peut maintenant lire une source simple, inverser une source, combiner plusieurs sources, prendre le minimum ou le maximum, ou mesurer un ecart a une cible. Les formules restent bornees et auditables.</p>
                <div class="space-y-4">
                    @foreach ($settings as $code => $definition)
                        <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 dark:border-slate-700 dark:bg-slate-900/40">
                            <input type="hidden" name="definitions[{{ $code }}][code]" value="{{ $code }}">
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <div>
                                    <label for="kpi_label_{{ $code }}">Libelle</label>
                                    <input id="kpi_label_{{ $code }}" name="definitions[{{ $code }}][label]" type="text" maxlength="60" value="{{ old("definitions.$code.label", $definition['label'] ?? '') }}" required>
                                </div>
                                <div>
                                    <label for="kpi_weight_{{ $code }}">Poids</label>
                                    <input id="kpi_weight_{{ $code }}" name="definitions[{{ $code }}][weight]" type="number" min="0" max="100" value="{{ old("definitions.$code.weight", $definition['weight'] ?? 0) }}" required>
                                </div>
                                <div>
                                    <label for="kpi_green_{{ $code }}">Seuil vert</label>
                                    <input id="kpi_green_{{ $code }}" name="definitions[{{ $code }}][green_threshold]" type="number" step="0.01" min="0" max="100" value="{{ old("definitions.$code.green_threshold", $definition['green_threshold'] ?? 80) }}" required>
                                </div>
                                <div>
                                    <label for="kpi_orange_{{ $code }}">Seuil orange</label>
                                    <input id="kpi_orange_{{ $code }}" name="definitions[{{ $code }}][orange_threshold]" type="number" step="0.01" min="0" max="100" value="{{ old("definitions.$code.orange_threshold", $definition['orange_threshold'] ?? 60) }}" required>
                                </div>
                                <div class="md:col-span-2 xl:col-span-4">
                                    <label for="kpi_description_{{ $code }}">Description</label>
                                    <textarea id="kpi_description_{{ $code }}" name="definitions[{{ $code }}][description]" rows="2">{{ old("definitions.$code.description", $definition['description'] ?? '') }}</textarea>
                                </div>
                                <div>
                                    <label for="kpi_source_metric_{{ $code }}">Metrique source</label>
                                    <select id="kpi_source_metric_{{ $code }}" name="definitions[{{ $code }}][source_metric]" required>
                                        @foreach ($sourceMetricOptions as $metricCode => $metricLabel)
                                            <option value="{{ $metricCode }}" @selected(old("definitions.$code.source_metric", $definition['source_metric'] ?? $code) === $metricCode)>{{ $metricLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="kpi_formula_mode_{{ $code }}">Mode de lecture</label>
                                    <select id="kpi_formula_mode_{{ $code }}" name="definitions[{{ $code }}][formula_mode]" required>
                                        @foreach ($formulaModeOptions as $formulaCode => $formulaLabel)
                                            <option value="{{ $formulaCode }}" @selected(old("definitions.$code.formula_mode", $definition['formula_mode'] ?? 'direct') === $formulaCode)>{{ $formulaLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="kpi_secondary_metric_{{ $code }}">Source secondaire</label>
                                    <select id="kpi_secondary_metric_{{ $code }}" name="definitions[{{ $code }}][secondary_metric]">
                                        <option value="">Aucune</option>
                                        @foreach ($sourceMetricOptions as $metricCode => $metricLabel)
                                            <option value="{{ $metricCode }}" @selected(old("definitions.$code.secondary_metric", $definition['secondary_metric'] ?? '') === $metricCode)>{{ $metricLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="kpi_tertiary_metric_{{ $code }}">Source tertiaire</label>
                                    <select id="kpi_tertiary_metric_{{ $code }}" name="definitions[{{ $code }}][tertiary_metric]">
                                        <option value="">Aucune</option>
                                        @foreach ($sourceMetricOptions as $metricCode => $metricLabel)
                                            <option value="{{ $metricCode }}" @selected(old("definitions.$code.tertiary_metric", $definition['tertiary_metric'] ?? '') === $metricCode)>{{ $metricLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="kpi_secondary_weight_{{ $code }}">Poids source secondaire</label>
                                    <input id="kpi_secondary_weight_{{ $code }}" name="definitions[{{ $code }}][secondary_weight]" type="number" min="0" max="100" value="{{ old("definitions.$code.secondary_weight", $definition['secondary_weight'] ?? 0) }}">
                                </div>
                                <div>
                                    <label for="kpi_tertiary_weight_{{ $code }}">Poids source tertiaire</label>
                                    <input id="kpi_tertiary_weight_{{ $code }}" name="definitions[{{ $code }}][tertiary_weight]" type="number" min="0" max="100" value="{{ old("definitions.$code.tertiary_weight", $definition['tertiary_weight'] ?? 0) }}">
                                </div>
                                <div>
                                    <label for="kpi_target_value_{{ $code }}">Cible numerique</label>
                                    <input id="kpi_target_value_{{ $code }}" name="definitions[{{ $code }}][target_value]" type="number" step="0.01" min="0" max="100" value="{{ old("definitions.$code.target_value", $definition['target_value'] ?? '') }}">
                                </div>
                                <div>
                                    <label for="kpi_adjustment_{{ $code }}">Ajustement final</label>
                                    <input id="kpi_adjustment_{{ $code }}" name="definitions[{{ $code }}][adjustment]" type="number" step="0.01" min="-100" max="100" value="{{ old("definitions.$code.adjustment", $definition['adjustment'] ?? 0) }}">
                                </div>
                                <div class="md:col-span-2 xl:col-span-4">
                                    <div class="rounded-2xl border border-dashed border-slate-300/80 bg-slate-50/80 px-4 py-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-300">
                                        Presets disponibles :
                                        <span class="font-medium">direct</span>,
                                        <span class="font-medium">inverse</span>,
                                        <span class="font-medium">moyenne ponderee</span>,
                                        <span class="font-medium">ecart a la cible</span>,
                                        <span class="font-medium">minimum</span>,
                                        <span class="font-medium">maximum</span>.
                                        Pour la moyenne ponderee, la source principale recupere automatiquement le poids restant.
                                    </div>
                                </div>
                                <div class="md:col-span-2 xl:col-span-3">
                                    <span class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Profils cibles</span>
                                    <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                                        @foreach ($profileOptions as $profileCode => $profileLabel)
                                            <label class="checkbox-pill">
                                                <input type="checkbox" name="definitions[{{ $code }}][target_profiles][]" value="{{ $profileCode }}" @checked(in_array($profileCode, old("definitions.$code.target_profiles", $definition['target_profiles'] ?? []), true))>
                                                <span>{{ $profileLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="md:col-span-2">
                                    <label for="kpi_target_directions_{{ $code }}">Directions cibles</label>
                                    <select id="kpi_target_directions_{{ $code }}" name="definitions[{{ $code }}][target_direction_ids][]" multiple size="5">
                                        @foreach ($directionOptions as $direction)
                                            <option value="{{ $direction->id }}" @selected(in_array((int) $direction->id, array_map('intval', old("definitions.$code.target_direction_ids", $definition['target_direction_ids'] ?? [])), true))>{{ $direction->code }} - {{ $direction->libelle }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="md:col-span-2">
                                    <label for="kpi_target_services_{{ $code }}">Services cibles</label>
                                    <select id="kpi_target_services_{{ $code }}" name="definitions[{{ $code }}][target_service_ids][]" multiple size="5">
                                        @foreach ($serviceOptions as $service)
                                            <option value="{{ $service->id }}" @selected(in_array((int) $service->id, array_map('intval', old("definitions.$code.target_service_ids", $definition['target_service_ids'] ?? [])), true))>{{ $service->direction?->code }} / {{ $service->code }} - {{ $service->libelle }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex items-end">
                                    <label class="checkbox-pill !mb-0">
                                        <input type="checkbox" name="definitions[{{ $code }}][visible]" value="1" @checked((bool) old("definitions.$code.visible", $definition['visible'] ?? true))>
                                        Visible dans les syntheses
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Enregistrer le registre KPI</button>
            </div>
        </form>
    </section>
@endsection

