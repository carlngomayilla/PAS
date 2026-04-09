@extends('layouts.workspace')

@section('title', 'Roles et permissions')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Roles et permissions</h1>
                <p class="mt-2 text-slate-600">Matrice des droits systeme. Les roles natifs restent verrouilles; seul le registre de permissions est modulable.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.modules.edit') }}">Modules et navigation</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.appearance.edit') }}">Apparence</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))] mb-3.5">
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Roles systeme</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ count($roles) }}</p>
            <p class="mt-2 text-sm text-slate-600">Les roles natifs restent verrouilles. Cette matrice pilote uniquement leurs droits effectifs.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Role simule</p>
            <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $selectedRoleLabel }}</p>
            <p class="mt-2 text-sm text-slate-600">{{ count($selectedPermissions) }} permissions actives et {{ count($selectedModules) }} modules visibles.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Permissions sensibles</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ collect($permissionGroups)->sum(fn ($group) => collect($group['permissions'])->where('sensitive', true)->count()) }}</p>
            <p class="mt-2 text-sm text-slate-600">Les modifications sont journalisees et le role `super_admin` reste force a l ensemble des permissions.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Roles personnalises</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ count($customRoles) }}</p>
            <p class="mt-2 text-sm text-slate-600">Chaque role personnalise herite d un role de base pour conserver le scope natif.</p>
        </article>
    </section>

    @php
        $customRoleRows = collect($customRoles)->map(function (array $role, string $code): array {
            return [
                'code' => $code,
                'label' => $role['label'] ?? $code,
                'base_role' => $role['base_role'] ?? \App\Models\User::ROLE_AGENT,
                'description' => $role['description'] ?? '',
                'active' => true,
            ];
        })->values();
        while ($customRoleRows->count() < 6) {
            $customRoleRows->push([
                'code' => '',
                'label' => '',
                'base_role' => \App\Models\User::ROLE_AGENT,
                'description' => '',
                'active' => false,
            ]);
        }
    @endphp

    <section class="ui-card mb-3.5">
        <form method="POST" action="{{ route('workspace.super-admin.roles.registry.update') }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="form-section-title">Registre des roles personnalises</h2>
                        <p class="form-section-subtitle">Le code du role personnalise devient le profil effectif du compte. Le role de base conserve la logique de perimetre existante.</p>
                    </div>
                    <button class="btn btn-primary" type="submit">Enregistrer les roles personnalises</button>
                </div>

                <div class="mt-4 space-y-4">
                    @foreach ($customRoleRows as $index => $row)
                        <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 dark:border-slate-700 dark:bg-slate-900/40">
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                                <div>
                                    <label for="custom_role_code_{{ $index }}">Code</label>
                                    <input id="custom_role_code_{{ $index }}" name="custom_roles[{{ $index }}][code]" type="text" value="{{ old("custom_roles.$index.code", $row['code']) }}" placeholder="ex. chef_projet">
                                </div>
                                <div>
                                    <label for="custom_role_label_{{ $index }}">Libelle</label>
                                    <input id="custom_role_label_{{ $index }}" name="custom_roles[{{ $index }}][label]" type="text" value="{{ old("custom_roles.$index.label", $row['label']) }}" placeholder="Chef de projet">
                                </div>
                                <div>
                                    <label for="custom_role_base_{{ $index }}">Role de base</label>
                                    <select id="custom_role_base_{{ $index }}" name="custom_roles[{{ $index }}][base_role]">
                                        @foreach ($baseRoleOptions as $roleCode => $definition)
                                            <option value="{{ $roleCode }}" @selected(old("custom_roles.$index.base_role", $row['base_role']) === $roleCode)>{{ $definition['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="xl:col-span-2">
                                    <label for="custom_role_description_{{ $index }}">Description</label>
                                    <input id="custom_role_description_{{ $index }}" name="custom_roles[{{ $index }}][description]" type="text" value="{{ old("custom_roles.$index.description", $row['description']) }}" placeholder="Description interne optionnelle">
                                </div>
                                <div class="md:col-span-2 xl:col-span-5 flex items-end gap-3">
                                    <label class="checkbox-pill !mb-0">
                                        <input name="custom_roles[{{ $index }}][active]" type="checkbox" value="1" @checked(old("custom_roles.$index.active", $row['active']))>
                                        Active
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </form>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" action="{{ route('workspace.super-admin.roles.update', ['simulate_role' => $selectedRole]) }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="form-section-title">Matrice de permissions</h2>
                        <p class="form-section-subtitle">Un role sans permission ne voit plus le module correspondant et perd l acces direct a ses routes principales.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a class="btn btn-secondary" href="{{ route('workspace.super-admin.roles.edit', ['simulate_role' => $selectedRole]) }}">Recharger</a>
                        <button class="btn btn-primary" type="submit">Enregistrer la matrice</button>
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left">Permission</th>
                                @foreach ($roles as $roleCode => $roleLabel)
                                    <th class="px-3 py-2 text-center">{{ $roleLabel }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($permissionGroups as $group)
                                <tr>
                                    <td class="px-3 py-3 font-semibold text-slate-900 dark:text-slate-100" colspan="{{ count($roles) + 1 }}">
                                        {{ $group['group'] }}
                                    </td>
                                </tr>
                                @foreach ($group['permissions'] as $permission)
                                    <tr>
                                        <td class="px-3 py-3 align-top">
                                            <div class="font-medium text-slate-900 dark:text-slate-100">{{ $permission['label'] }}</div>
                                            <div class="mt-1 text-xs text-slate-500">{{ $permission['code'] }}</div>
                                            <div class="mt-1 text-sm text-slate-600">{{ $permission['description'] }}</div>
                                            @if ($permission['sensitive'])
                                                <div class="mt-2 inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">Sensible</div>
                                            @endif
                                        </td>
                                        @foreach ($roles as $roleCode => $roleLabel)
                                            @php($checked = in_array($permission['code'], $matrix[$roleCode] ?? [], true))
                                            <td class="px-3 py-3 text-center align-top">
                                                <input
                                                    class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                                    type="checkbox"
                                                    name="permissions[{{ $roleCode }}][]"
                                                    value="{{ $permission['code'] }}"
                                                    @checked($checked)
                                                    @disabled($roleCode === \App\Models\User::ROLE_SUPER_ADMIN)
                                                >
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </section>

    <section class="grid gap-3 lg:grid-cols-[320px,minmax(0,1fr)]">
        <article class="ui-card !mb-0">
            <form method="GET" action="{{ route('workspace.super-admin.roles.edit') }}" class="form-shell">
                <div class="form-section">
                    <h2 class="form-section-title">Simulation</h2>
                    <p class="form-section-subtitle">Previsualisation theorique du perimetre rendu par la matrice actuelle.</p>
                    <div class="form-grid">
                        <div class="md:col-span-2 xl:col-span-4">
                            <label for="simulate_role">Role a simuler</label>
                            <select id="simulate_role" name="simulate_role">
                                @foreach ($roles as $roleCode => $roleLabel)
                                    <option value="{{ $roleCode }}" @selected($selectedRole === $roleCode)>{{ $roleLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-secondary" type="submit">Simuler</button>
                </div>
            </form>
        </article>

        <article class="ui-card !mb-0">
            <h2>Resultat de la simulation</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Permissions actives</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($selectedPermissions as $permissionCode)
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $permissionCode }}</span>
                        @empty
                            <p class="text-sm text-slate-500">Aucune permission active.</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Modules visibles</h3>
                    <div class="mt-3 space-y-3">
                        @forelse ($selectedModules as $module)
                            <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-3 dark:border-slate-700 dark:bg-slate-900/40">
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ $module['label'] }}</div>
                                <div class="mt-1 text-sm text-slate-600">{{ $module['description'] }}</div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Aucun module visible pour ce role avec la configuration actuelle.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </article>
    </section>

    <section class="grid gap-3 lg:grid-cols-[320px,minmax(0,1fr)] mt-3.5">
        <article class="ui-card !mb-0">
            <form method="GET" action="{{ route('workspace.super-admin.roles.edit') }}" class="form-shell">
                <div class="form-section">
                    <h2 class="form-section-title">Comparaison de roles</h2>
                    <p class="form-section-subtitle">Compare les ecarts de permissions et de modules visibles entre deux profils systeme.</p>
                    <div class="form-grid">
                        <div class="md:col-span-2 xl:col-span-4">
                            <label for="compare_left_role">Role de reference</label>
                            <select id="compare_left_role" name="compare_left_role">
                                @foreach ($roles as $roleCode => $roleLabel)
                                    <option value="{{ $roleCode }}" @selected($compareLeftRole === $roleCode)>{{ $roleLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2 xl:col-span-4">
                            <label for="compare_right_role">Role a comparer</label>
                            <select id="compare_right_role" name="compare_right_role">
                                @foreach ($roles as $roleCode => $roleLabel)
                                    <option value="{{ $roleCode }}" @selected($compareRightRole === $roleCode)>{{ $roleLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-secondary" type="submit">Comparer</button>
                </div>
            </form>
        </article>

        <article class="ui-card !mb-0">
            <h2>Ecarts de permissions</h2>
            <div class="mt-4 grid gap-4 xl:grid-cols-3">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Communes</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($roleComparison['shared'] as $permissionCode)
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200">{{ $permissionCode }}</span>
                        @empty
                            <p class="text-sm text-slate-500">Aucune permission commune.</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $roles[$compareLeftRole] ?? $compareLeftRole }} uniquement</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($roleComparison['left_only'] as $permissionCode)
                            <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-500/20 dark:text-blue-200">{{ $permissionCode }}</span>
                        @empty
                            <p class="text-sm text-slate-500">Aucun ecart cote reference.</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $roles[$compareRightRole] ?? $compareRightRole }} uniquement</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($roleComparison['right_only'] as $permissionCode)
                            <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-200">{{ $permissionCode }}</span>
                        @empty
                            <p class="text-sm text-slate-500">Aucun ecart cote compare.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Modules visibles : {{ $roles[$compareLeftRole] ?? $compareLeftRole }}</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($roleComparison['left_modules'] as $moduleLabel)
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $moduleLabel }}</span>
                        @empty
                            <p class="text-sm text-slate-500">Aucun module visible.</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Modules visibles : {{ $roles[$compareRightRole] ?? $compareRightRole }}</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($roleComparison['right_modules'] as $moduleLabel)
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $moduleLabel }}</span>
                        @empty
                            <p class="text-sm text-slate-500">Aucun module visible.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </article>
    </section>
@endsection

