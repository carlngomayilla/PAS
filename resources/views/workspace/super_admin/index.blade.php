@extends('layouts.workspace')

@section('title', 'Super Administration')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Pilotage de la plateforme</h1>
                <p class="mt-2 text-slate-600">Configuration profonde, gouvernance du workflow, des templates d export et controle des changements systeme.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Utilisateurs actifs</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['active_users'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Modules actifs</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['modules_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Theme par defaut</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ ucfirst($summary['default_theme']) }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Locale / fuseau</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ strtoupper($summary['default_locale']) }}</p><p class="mt-1 text-sm text-slate-600">{{ $summary['default_timezone'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Maintenance</p><p class="mt-2 text-2xl font-bold {{ $summary['maintenance_active'] ? 'text-amber-600 dark:text-amber-300' : 'text-emerald-600 dark:text-emerald-300' }}">{{ $summary['maintenance_active'] ? 'Active' : 'Inactive' }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Templates publies</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['templates_published'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Affectations actives</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['assignments_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Modifications systeme</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['system_changes'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Templates total</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['templates_total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Base statistique actions</p><p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['official_base'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Groupes de permissions</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['permission_groups'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Dashboards pilotables</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['dashboard_profiles'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Referentiels dynamiques</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['dynamic_referentials'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">KPI pilotes visibles</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['managed_kpis'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Events notification actifs</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['notification_events_enabled'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Regles temporelles</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['timeline_rules_enabled'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Alertes diagnostic</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['diagnostic_alerts'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Sessions actives</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['sessions_active'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Snapshots configuration</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['configuration_snapshots'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Seuil cloture actions</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['action_policy_closure_threshold'] }}%</p></article>
    </section>

    <section class="ui-card mb-3.5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2>Acces rapides</h2>
                <p class="text-slate-600">Socle implemente : parametres globaux, workflow des validations et templates d export.</p>
            </div>
            <a class="btn btn-primary" href="{{ route('workspace.super-admin.templates.create') }}">Nouveau template</a>
        </div>
        <div class="mt-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            <article class="ui-card !mb-0"><strong>Parametres generaux</strong><p class="mt-2 text-slate-600">Nom applicatif, intitules login, textes globaux, footer et libelles d interface.</p></article>
            <article class="ui-card !mb-0"><strong>Modules et navigation</strong><p class="mt-2 text-slate-600">Renommage, ordre et visibilite globale des modules dans le workspace et la sidebar.</p></article>
            <article class="ui-card !mb-0"><strong>Roles et permissions</strong><p class="mt-2 text-slate-600">Matrice centralisee des droits systeme, simulation des modules visibles et verrouillage des roles natifs.</p></article>
            <article class="ui-card !mb-0"><strong>Organisation et utilisateurs</strong><p class="mt-2 text-slate-600">Activation rapide des directions, services et comptes, lecture des sessions et reinitialisation controlee des mots de passe.</p></article>
            <article class="ui-card !mb-0"><strong>Dashboards par profil</strong><p class="mt-2 text-slate-600">Visibilite, ordre des cartes et activation des blocs analytiques par role metier.</p></article>
            <article class="ui-card !mb-0"><strong>Referentiels dynamiques</strong><p class="mt-2 text-slate-600">Pilotage des priorites operationnelles, des libelles de cible action et des suggestions d unites sans toucher au code.</p></article>
            <article class="ui-card !mb-0"><strong>Documents et justificatifs</strong><p class="mt-2 text-slate-600">Formats autorises, retention, droits de consultation et televersement par categorie documentaire.</p></article>
            <article class="ui-card !mb-0"><strong>KPI et statistiques</strong><p class="mt-2 text-slate-600">Registre pilote des indicateurs consolides, seuils de lecture et profils cibles du reporting statistique.</p></article>
            <article class="ui-card !mb-0"><strong>Apparence</strong><p class="mt-2 text-slate-600">Palette, typographie, theme par defaut et habillage global clair ou sombre.</p></article>
            <article class="ui-card !mb-0"><strong>Workflow et validations</strong><p class="mt-2 text-slate-600">Activation des etapes service et direction, avec regles de rejet coherentes en web et en API.</p></article>
            <article class="ui-card !mb-0"><strong>Politique de calcul</strong><p class="mt-2 text-slate-600">Les KPI et statistiques consolides portent sur toutes les actions visibles. Les validations chef et direction restent reservees au workflow de cloture.</p></article>
            <article class="ui-card !mb-0"><strong>Parametres metier des actions</strong><p class="mt-2 text-slate-600">Regles de risque, suspension manuelle, seuil minimal de cloture et auto-cloture a la cible.</p></article>
            <article class="ui-card !mb-0"><strong>Alertes et notifications</strong><p class="mt-2 text-slate-600">Activation des evenements de notification et surcouche d escalade par niveau d alerte.</p></article>
            <article class="ui-card !mb-0"><strong>Sauvegarde / restauration</strong><p class="mt-2 text-slate-600">Snapshots complets des parametres `platform_settings` avec restauration tracee.</p></article>
            <article class="ui-card !mb-0"><strong>Simulation</strong><p class="mt-2 text-slate-600">Comparaison avant application des impacts workflow / base statistique / cloture des actions.</p></article>
            <article class="ui-card !mb-0"><strong>Audit et diagnostic</strong><p class="mt-2 text-slate-600">Lecture concentree des changements sensibles et controles de coherence sur les donnees de pilotage.</p></article>
            <article class="ui-card !mb-0"><strong>Templates d export</strong><p class="mt-2 text-slate-600">Creation, duplication, publication, archivage et affectation.</p></article>
            <article class="ui-card !mb-0"><strong>Maintenance legere</strong><p class="mt-2 text-slate-600">Purge des caches, regeneration des vues et bascule du mode maintenance avec journalisation.</p></article>
            <article class="ui-card !mb-0"><strong>Versionning</strong><p class="mt-2 text-slate-600">Chaque publication produit une version horodatee et auditable.</p></article>
            <article class="ui-card !mb-0"><strong>Affectations par profil</strong><p class="mt-2 text-slate-600">Resolution par module, format, profil, niveau de lecture et scope organisationnel.</p></article>
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Historique recent</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Utilisateur</th><th class="px-3 py-2 text-left">Module</th><th class="px-3 py-2 text-left">Action</th></tr></thead>
                <tbody>
                    @forelse ($recentAudits as $audit)
                        <tr>
                            <td class="px-3 py-2">{{ $audit->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">{{ $audit->user?->name ?? 'Systeme' }}</td>
                            <td class="px-3 py-2">{{ $audit->module }}</td>
                            <td class="px-3 py-2">{{ $audit->action }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-4 text-slate-500" colspan="4">Aucune modification super admin journalisee pour le moment.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
