@extends('layouts.workspace')

@section('title', 'Super Administration')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h1 class="mt-2">Pilotage de la plateforme</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Utilisateurs actifs</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['active_users'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Modules actifs</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['modules_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Thème par défaut</p><p class="mt-2 text-2xl font-bold text-slate-900">{{ ucfirst($summary['default_theme']) }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Locale / fuseau</p><p class="mt-2 text-2xl font-bold text-slate-900">{{ strtoupper($summary['default_locale']) }}</p><p class="mt-1 text-sm text-slate-600">{{ $summary['default_timezone'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Maintenance</p><p class="mt-2 text-2xl font-bold {{ $summary['maintenance_active'] ? 'text-amber-600' : 'text-emerald-600' }}">{{ $summary['maintenance_active'] ? 'Active' : 'Inactive' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Templates publiés</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['templates_published'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Affectations actives</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['assignments_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Modifications système</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['system_changes'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Templates total</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['templates_total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Base statistique actions</p><p class="mt-2 text-2xl font-bold text-slate-900">{{ $summary['official_base'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Groupes de permissions</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['permission_groups'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Dashboards pilotables</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['dashboard_profiles'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Référentiels dynamiques</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['dynamic_referentials'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Indicateur de performance pilotes visibles</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['managed_kpis'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Events notification actifs</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['notification_events_enabled'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Règles temporelles</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['timeline_rules_enabled'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Alertes diagnostic</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['diagnostic_alerts'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Sessions actives</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['sessions_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Snapshots configuration</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['configuration_snapshots'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Seuil clôture actions</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $summary['action_policy_closure_threshold'] }}%</p></article>
    </section>

    <section class="showcase-panel mb-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Accès rapides</h2>
            </div>
            <a class="btn btn-primary" href="{{ route('workspace.super-admin.templates.create') }}">Nouveau template</a>
        </div>
        <div class="mt-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            <article class="ui-card !mb-0"><strong>Parametres generaux</strong></article>
            <article class="ui-card !mb-0"><strong>Modules et navigation</strong></article>
            <article class="ui-card !mb-0"><strong>Rôles et permissions</strong></article>
            <article class="ui-card !mb-0"><strong>Organisation et utilisateurs</strong></article>
            <article class="ui-card !mb-0"><strong>Dashboards par profil</strong></article>
            <article class="ui-card !mb-0"><strong>Référentiels dynamiques</strong></article>
            <article class="ui-card !mb-0"><strong>Documents et justificatifs</strong></article>
            <article class="ui-card !mb-0"><strong>Indicateur de performance et statistiques</strong></article>
            <article class="ui-card !mb-0"><strong>Apparence</strong></article>
            <article class="ui-card !mb-0"><strong>Workflow et validations</strong></article>
            <article class="ui-card !mb-0"><strong>Politique de calcul</strong></article>
            <article class="ui-card !mb-0"><strong>Parametres actions</strong></article>
            <article class="ui-card !mb-0"><strong>Alertes et notifications</strong></article>
            <article class="ui-card !mb-0"><strong>Sauvegarde / restauration</strong></article>
            <article class="ui-card !mb-0"><strong>Simulation</strong></article>
            <article class="ui-card !mb-0"><strong>Audit et diagnostic</strong></article>
            <article class="ui-card !mb-0"><strong>Templates d export</strong></article>
            <article class="ui-card !mb-0"><strong>Maintenance</strong></article>
            <article class="ui-card !mb-0"><strong>Versionning</strong></article>
            <article class="ui-card !mb-0"><strong>Affectations par profil</strong></article>
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2>Historique récent</h2>
        <div class="app-table-wrapper overflow-x-auto mt-4">
            <table class="app-table data-table">
                <thead><tr><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Utilisateur</th><th class="px-3 py-2 text-left">Module</th><th class="px-3 py-2 text-left">Action</th></tr></thead>
                <tbody>
                    @forelse ($recentAudits as $audit)
                        <tr>
                            <td class="px-3 py-2">{{ $audit->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">{{ $audit->user?->name ?? 'Système' }}</td>
                            <td class="px-3 py-2">{{ $audit->module }}</td>
                            <td class="px-3 py-2">{{ $audit->action }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <x-ui.empty-state
                                    title="Aucune modification journalisée"
                                    message="Les changements Super Admin apparaîtront ici dès qu'ils seront disponibles."
                                    icon="clock"
                                    tone="info"
                                    class="my-4"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
