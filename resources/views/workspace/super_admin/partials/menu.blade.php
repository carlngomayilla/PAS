@php
    $buttonLabel = $buttonLabel ?? 'Acces';
    $sections = [
        [
            'title' => 'Plateforme',
            'description' => 'Identite, navigation, maintenance.',
            'links' => [
                ['label' => 'Generaux', 'description' => 'Textes, logos, formats.', 'route' => route('workspace.super-admin.settings.edit'), 'active' => request()->routeIs('workspace.super-admin.settings.*')],
                ['label' => 'Apparence', 'description' => 'Palette, densite, lecture.', 'route' => route('workspace.super-admin.appearance.edit'), 'active' => request()->routeIs('workspace.super-admin.appearance.*')],
                ['label' => 'Modules', 'description' => 'Ordre et visibilité.', 'route' => route('workspace.super-admin.modules.edit'), 'active' => request()->routeIs('workspace.super-admin.modules.*')],
                ['label' => 'Maintenance', 'description' => 'Caches et actions techniques.', 'route' => route('workspace.super-admin.maintenance.index'), 'active' => request()->routeIs('workspace.super-admin.maintenance.*')],
            ],
        ],
        [
            'title' => 'Gouvernance',
            'description' => 'Acces, organisation, audit.',
            'links' => [
                ['label' => 'Roles', 'description' => 'Matrice, registre, comparaison.', 'route' => route('workspace.super-admin.roles.edit'), 'active' => request()->routeIs('workspace.super-admin.roles.*')],
                ['label' => 'Organisation', 'description' => 'Directions, services, comptes.', 'route' => route('workspace.super-admin.organization.index'), 'active' => request()->routeIs('workspace.super-admin.organization.*')],
                ['label' => 'Dashboards', 'description' => 'Cartes et visibilité.', 'route' => route('workspace.super-admin.dashboard-profiles.edit'), 'active' => request()->routeIs('workspace.super-admin.dashboard-profiles.*')],
                ['label' => 'Diagnostic', 'description' => 'Contrôle plateforme et incidents.', 'route' => route('workspace.super-admin.audit-diagnostic.index'), 'active' => request()->routeIs('workspace.super-admin.audit-diagnostic.*')],
                ['label' => 'Audit', 'description' => 'Journal des actions sensibles.', 'route' => route('workspace.audit.index'), 'active' => request()->routeIs('workspace.audit.*')],
            ],
        ],
        [
            'title' => 'Pilotage',
            'description' => 'Workflow, Indicateur de performance, referentiels.',
            'links' => [
                ['label' => 'Workflow', 'description' => 'Circuits Actions, PAS, PAO, PTA.', 'route' => route('workspace.super-admin.workflow.edit'), 'active' => request()->routeIs('workspace.super-admin.workflow.*')],
                ['label' => 'Calcul', 'description' => 'Base statistique et règles.', 'route' => route('workspace.super-admin.calculation.edit'), 'active' => request()->routeIs('workspace.super-admin.calculation.*')],
                ['label' => 'Actions', 'description' => 'Cloture, risque, suspension.', 'route' => route('workspace.super-admin.action-policies.edit'), 'active' => request()->routeIs('workspace.super-admin.action-policies.*')],
                ['label' => 'Referentiels', 'description' => 'Libelles, unites, priorites.', 'route' => route('workspace.super-admin.referentials.edit'), 'active' => request()->routeIs('workspace.super-admin.referentials.*')],
                ['label' => 'Documents', 'description' => 'Formats, retention, droits.', 'route' => route('workspace.super-admin.documents.edit'), 'active' => request()->routeIs('workspace.super-admin.documents.*')],
                ['label' => 'Indicateur de performance', 'description' => 'Registre et moteur no-code.', 'route' => route('workspace.super-admin.kpis.edit'), 'active' => request()->routeIs('workspace.super-admin.kpis.*')],
                ['label' => 'Notifications', 'description' => 'Evenements, escalades, delais.', 'route' => route('workspace.super-admin.notifications.edit'), 'active' => request()->routeIs('workspace.super-admin.notifications.*')],
            ],
        ],
        [
            'title' => 'Avance',
            'description' => 'Snapshots, simulation, exports.',
            'links' => [
                ['label' => 'Snapshots', 'description' => 'Comparaison et restauration.', 'route' => route('workspace.super-admin.snapshots.index'), 'active' => request()->routeIs('workspace.super-admin.snapshots.*')],
                ['label' => 'Simulation', 'description' => 'Impact avant application.', 'route' => route('workspace.super-admin.simulation.index'), 'active' => request()->routeIs('workspace.super-admin.simulation.*')],
                ['label' => 'Templates', 'description' => 'Designer, versions, affectations.', 'route' => route('workspace.super-admin.templates.index'), 'active' => request()->routeIs('workspace.super-admin.templates.*')],
            ],
        ],
    ];
    $totalLinks = collect($sections)->sum(fn (array $section): int => count($section['links']));
@endphp

<details class="super-admin-menu">
    <summary class="super-admin-menu-trigger" aria-label="{{ $buttonLabel }}">
        <span class="super-admin-menu-trigger-copy">
            <span class="super-admin-menu-trigger-eyebrow">Super Admin</span>
            <span class="super-admin-menu-trigger-label">{{ $buttonLabel }}</span>
        </span>
        <span class="super-admin-menu-trigger-meta">
            <span class="super-admin-menu-trigger-count">{{ $totalLinks }}</span>
            <span class="super-admin-menu-trigger-caret" aria-hidden="true"></span>
        </span>
    </summary>

    <div class="super-admin-menu-panel">
        <div class="super-admin-menu-grid">
            @foreach ($sections as $section)
                <section class="super-admin-menu-group">
                    <div class="super-admin-menu-group-head">
                        <p class="super-admin-menu-title">{{ $section['title'] }}</p>
                    </div>

                    <div class="super-admin-menu-links">
                        @foreach ($section['links'] as $link)
                            <a class="super-admin-menu-link{{ $link['active'] ? ' is-active' : '' }}" href="{{ $link['route'] }}">
                                <span class="super-admin-menu-link-label">{{ $link['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</details>
