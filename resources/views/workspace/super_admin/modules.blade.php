@extends('layouts.workspace')

@section('title', 'Modules et navigation')

@section('content')
    @php
        $publishedVisibleCount = $publishedModules->where('enabled', true)->count();
        $editableVisibleCount = $modules->where('enabled', true)->count();
    @endphp

    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Modules et navigation</h1>
                <p class="mt-2 text-slate-600">Pilotage global des libelles, de l ordre et de la visibilite des modules dans le workspace. Les droits metier par role restent appliques a part.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.appearance.edit') }}">Apparence</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.templates.index') }}">Templates d export</a>
            </div>
        </div>
    </section>

    <x-super-admin.draft-banner
        :has-draft="$hasDraft"
        :draft-updated-at="$draftUpdatedAt"
        message="La navigation publique n a pas encore ete modifiee"
        :publish-route="route('workspace.super-admin.modules.publish-draft')"
        :discard-route="route('workspace.super-admin.modules.discard-draft')"
    />

    <x-super-admin.compare-panels :editable-title="$hasDraft ? 'Brouillon / edition' : 'Version en edition'">
        <x-slot:published>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500">Modules visibles</p>
                    <p class="mt-1 text-3xl font-semibold">{{ $publishedVisibleCount }}</p>
                </div>
                <p class="text-sm text-slate-500">Ordre public</p>
            </div>
            <div class="mt-4 space-y-2">
                @foreach ($publishedModules as $module)
                    <div class="flex items-center justify-between rounded-2xl border border-slate-200/80 bg-white/90 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900/80">
                        <div>
                            <p class="font-semibold">{{ $module['label'] }}</p>
                            <p class="text-slate-500">{{ $module['description'] }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-slate-500">#{{ $module['order'] }}</p>
                            <p class="{{ $module['enabled'] ? 'text-emerald-700' : 'text-slate-400' }}">{{ $module['enabled'] ? 'Visible' : 'Masque' }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-slot:published>

        <x-slot:editable>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500">Modules visibles</p>
                    <p class="mt-1 text-3xl font-semibold">{{ $editableVisibleCount }}</p>
                </div>
                <p class="text-sm text-slate-500">Ordre en edition</p>
            </div>
            <div class="mt-4 space-y-2">
                @foreach ($modules as $module)
                    @php
                        $published = $publishedModules->firstWhere('code', $module['code']);
                        $changed = ! $published
                            || $published['label'] !== $module['label']
                            || $published['description'] !== $module['description']
                            || (int) $published['order'] !== (int) $module['order']
                            || (bool) $published['enabled'] !== (bool) $module['enabled'];
                    @endphp
                    <div class="flex items-center justify-between rounded-2xl border px-4 py-3 text-sm {{ $changed ? 'border-blue-300/80 bg-blue-50/60 dark:border-blue-700/60 dark:bg-blue-950/20' : 'border-slate-200/80 bg-white/90 dark:border-slate-700 dark:bg-slate-900/80' }}">
                        <div>
                            <p class="font-semibold">{{ $module['label'] }}</p>
                            <p class="text-slate-500">{{ $module['description'] }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-slate-500">#{{ $module['order'] }}</p>
                            <p class="{{ $module['enabled'] ? 'text-emerald-700' : 'text-slate-400' }}">{{ $module['enabled'] ? 'Visible' : 'Masque' }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-slot:editable>
    </x-super-admin.compare-panels>

    <section class="ui-card mb-3.5">
        <form method="POST" action="{{ route('workspace.super-admin.modules.update') }}" class="form-shell" id="modules-settings-form">
            @csrf
            <input id="modules-settings-method-spoof" name="_method" type="hidden" value="PUT">

            <div class="form-section">
                <h2 class="form-section-title">Registre des modules</h2>
                <p class="form-section-subtitle">Le module `Super Administration` reste toujours actif pour eviter un verrouillage de la plateforme.</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left">Code</th>
                                <th class="px-3 py-2 text-left">Libelle visible</th>
                                <th class="px-3 py-2 text-left">Description</th>
                                <th class="px-3 py-2 text-left">Ordre</th>
                                <th class="px-3 py-2 text-left">Section</th>
                                <th class="px-3 py-2 text-left">Etat</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($modules as $module)
                                @php($code = (string) $module['code'])
                                <tr>
                                    <td class="px-3 py-3 font-semibold text-slate-900 dark:text-slate-100">{{ $code }}</td>
                                    <td class="px-3 py-3">
                                        <input
                                            type="text"
                                            name="modules[{{ $code }}][label]"
                                            value="{{ old("modules.$code.label", $module['label']) }}"
                                            maxlength="80"
                                            required
                                        >
                                    </td>
                                    <td class="px-3 py-3">
                                        <input
                                            type="text"
                                            name="modules[{{ $code }}][description]"
                                            value="{{ old("modules.$code.description", $module['description']) }}"
                                            maxlength="255"
                                            required
                                        >
                                    </td>
                                    <td class="px-3 py-3 max-w-24">
                                        <input
                                            type="number"
                                            name="modules[{{ $code }}][order]"
                                            value="{{ old("modules.$code.order", $module['order']) }}"
                                            min="1"
                                            max="999"
                                            required
                                        >
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="anbg-badge anbg-badge-info">{{ ucfirst((string) $module['section']) }}</span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <input type="hidden" name="modules[{{ $code }}][enabled]" value="0">
                                        <label class="checkbox-pill">
                                            <input
                                                type="checkbox"
                                                name="modules[{{ $code }}][enabled]"
                                                value="1"
                                                @checked((bool) old("modules.$code.enabled", $module['enabled']))
                                                @disabled($code === 'super_admin')
                                            >
                                            <span>{{ $code === 'super_admin' ? 'Toujours actif' : 'Visible dans le workspace' }}</span>
                                        </label>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-secondary" type="submit" data-draft-action="{{ route('workspace.super-admin.modules.draft') }}" data-draft-method="POST">Enregistrer le brouillon</button>
                <button class="btn btn-primary" type="submit" data-draft-action="{{ route('workspace.super-admin.modules.update') }}" data-draft-method="PUT">Publier maintenant</button>
            </div>
        </form>
    </section>

    @push('scripts')
        <script>
            (function () {
                var form = document.getElementById('modules-settings-form');
                var methodInput = document.getElementById('modules-settings-method-spoof');
                if (!form || !methodInput) {
                    return;
                }

                var defaultAction = form.getAttribute('action');

                function applySubmitIntent(button) {
                    if (!button) {
                        return;
                    }

                    var action = button.getAttribute('data-draft-action') || defaultAction;
                    var method = (button.getAttribute('data-draft-method') || 'PUT').toUpperCase();

                    form.setAttribute('action', action);

                    if (method === 'POST') {
                        methodInput.disabled = true;
                        methodInput.value = 'PUT';
                        return;
                    }

                    methodInput.disabled = false;
                    methodInput.value = method;
                }

                form.querySelectorAll('[data-draft-action]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        applySubmitIntent(this);
                    });
                });

                applySubmitIntent(form.querySelector('[data-draft-method="PUT"]'));
            })();
        </script>
    @endpush
@endsection

