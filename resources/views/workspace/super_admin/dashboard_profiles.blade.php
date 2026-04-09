@extends('layouts.workspace')

@section('title', 'Dashboards par profil')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Dashboards par profil</h1>
                <p class="mt-2 text-slate-600">Pilotage des cartes de synthese et des blocs analytiques par role metier, avec effet direct sur le dashboard.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('dashboard') }}">Voir le dashboard</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.index') }}">Retour super admin</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Profils pilotes</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['profiles_total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Cartes pilotables</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['cards_total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Vues detail actives</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['overviews_enabled'] }}</p></article>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" action="{{ route('workspace.super-admin.dashboard-profiles.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            @foreach ($roleOptions as $role => $label)
                @php
                    $profile = $profiles[$role] ?? [];
                    $cards = $profile['cards'] ?? [];
                @endphp
                <section class="rounded-3xl border border-slate-200/80 p-4 dark:border-slate-700/80">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2>{{ $label }}</h2>
                            <p class="text-slate-600">Activer ou masquer les blocs analytiques et reordonner les cartes de synthese.</p>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                            <label class="checkbox-pill !mb-0">
                                <input type="checkbox" name="profiles[{{ $role }}][overview_enabled]" value="1" @checked(old("profiles.$role.overview_enabled", $profile['overview_enabled'] ?? true))>
                                Vue detail
                            </label>
                            <label class="checkbox-pill !mb-0">
                                <input type="checkbox" name="profiles[{{ $role }}][comparison_chart_enabled]" value="1" @checked(old("profiles.$role.comparison_chart_enabled", $profile['comparison_chart_enabled'] ?? true))>
                                Comparaison
                            </label>
                            <label class="checkbox-pill !mb-0">
                                <input type="checkbox" name="profiles[{{ $role }}][status_chart_enabled]" value="1" @checked(old("profiles.$role.status_chart_enabled", $profile['status_chart_enabled'] ?? true))>
                                Statuts
                            </label>
                            <label class="checkbox-pill !mb-0">
                                <input type="checkbox" name="profiles[{{ $role }}][trend_chart_enabled]" value="1" @checked(old("profiles.$role.trend_chart_enabled", $profile['trend_chart_enabled'] ?? true))>
                                Tendance
                            </label>
                            <label class="checkbox-pill !mb-0">
                                <input type="checkbox" name="profiles[{{ $role }}][support_chart_enabled]" value="1" @checked(old("profiles.$role.support_chart_enabled", $profile['support_chart_enabled'] ?? true))>
                                Lecture metier
                            </label>
                        </div>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="dashboard-table">
                            <thead><tr><th>Carte</th><th>Visible</th><th>Ordre</th><th>Taille</th><th>Ton</th><th>Redirection</th><th>Filtres</th></tr></thead>
                            <tbody>
                                @foreach ($cards as $card)
                                    <tr>
                                        <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $card['label'] }}</td>
                                        <td>
                                            <label class="checkbox-pill !mb-0">
                                                <input type="checkbox" name="profiles[{{ $role }}][cards][{{ $card['code'] }}][enabled]" value="1" @checked(old("profiles.$role.cards.{$card['code']}.enabled", $card['enabled'] ?? true))>
                                                Visible
                                            </label>
                                        </td>
                                        <td class="w-[140px]">
                                            <input
                                                type="number"
                                                min="1"
                                                max="999"
                                                name="profiles[{{ $role }}][cards][{{ $card['code'] }}][order]"
                                                value="{{ old("profiles.$role.cards.{$card['code']}.order", $card['order'] ?? 10) }}"
                                            >
                                        </td>
                                        <td class="w-[150px]">
                                            <select name="profiles[{{ $role }}][cards][{{ $card['code'] }}][size]">
                                                @foreach ($cardSizeOptions as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}" @selected(old("profiles.$role.cards.{$card['code']}.size", $card['size'] ?? 'md') === $optionValue)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="w-[170px]">
                                            <select name="profiles[{{ $role }}][cards][{{ $card['code'] }}][tone]">
                                                @foreach ($cardToneOptions as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}" @selected(old("profiles.$role.cards.{$card['code']}.tone", $card['tone'] ?? 'auto') === $optionValue)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="w-[190px]">
                                            <select name="profiles[{{ $role }}][cards][{{ $card['code'] }}][target_route]">
                                                @foreach ($cardTargetRouteOptions as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}" @selected(old("profiles.$role.cards.{$card['code']}.target_route", $card['target_route'] ?? '') === $optionValue)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="min-w-[220px]">
                                            <input
                                                type="text"
                                                name="profiles[{{ $role }}][cards][{{ $card['code'] }}][target_filters]"
                                                value="{{ old("profiles.$role.cards.{$card['code']}.target_filters", $card['target_filters'] ?? '') }}"
                                                placeholder="statut=en_retard&scope=service"
                                            >
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Enregistrer</button>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Annuler</a>
            </div>
        </form>
    </section>
@endsection

