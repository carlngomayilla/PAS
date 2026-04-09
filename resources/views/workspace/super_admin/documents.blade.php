@extends('layouts.workspace')

@section('title', 'Documents et justificatifs')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Documents et justificatifs</h1>
                <p class="mt-2 text-slate-600">Pilotage des formats autorises, des tailles, de la retention et des droits de consultation ou televersement des justificatifs.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.referentials.edit') }}">Referentiels dynamiques</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.index') }}">Retour super admin</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Extensions autorisees</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['extensions_total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Roles upload</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['upload_roles_total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Roles consultation</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['view_roles_total'] }}</p></article>
        <article class="ui-card !mb-0"><p class="text-sm text-slate-500">Retention</p><p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['retention_days'] }} j</p></article>
    </section>

    <section class="ui-card mb-3.5">
        <form method="POST" action="{{ route('workspace.super-admin.documents.update') }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Politique documentaire</h2>
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="md:col-span-3">
                        <label for="allowed_extensions">Extensions autorisees</label>
                        <textarea id="allowed_extensions" name="allowed_extensions" rows="3" required>{{ old('allowed_extensions', implode(PHP_EOL, $settings['allowed_extensions'] ?? [])) }}</textarea>
                        <p class="mt-1 text-xs text-slate-500">Une extension par ligne. Exemples: pdf, docx, xlsx, png.</p>
                    </div>
                    <div>
                        <label for="max_upload_mb">Taille max (Mo)</label>
                        <input id="max_upload_mb" name="max_upload_mb" type="number" min="1" max="50" value="{{ old('max_upload_mb', $settings['max_upload_mb'] ?? 10) }}" required>
                    </div>
                    <div>
                        <label for="retention_days">Retention (jours)</label>
                        <input id="retention_days" name="retention_days" type="number" min="30" max="3650" value="{{ old('retention_days', $settings['retention_days'] ?? 365) }}" required>
                    </div>
                    <div>
                        <label for="accept_preview">Accept HTML genere</label>
                        <input id="accept_preview" type="text" value=".{{ implode(',.', $settings['allowed_extensions'] ?? []) }}" disabled>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Roles autorises</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <span class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Televersement</span>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($roleLabels as $roleCode => $roleLabel)
                                <label class="checkbox-pill">
                                    <input type="checkbox" name="upload_roles[]" value="{{ $roleCode }}" @checked(in_array($roleCode, old('upload_roles', $settings['upload_roles'] ?? []), true))>
                                    <span>{{ $roleLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <span class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Consultation</span>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($roleLabels as $roleCode => $roleLabel)
                                <label class="checkbox-pill">
                                    <input type="checkbox" name="view_roles[]" value="{{ $roleCode }}" @checked(in_array($roleCode, old('view_roles', $settings['view_roles'] ?? []), true))>
                                    <span>{{ $roleLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Visibilite par categorie</h2>
                <div class="space-y-4">
                    @foreach ($categoryLabels as $category => $label)
                        <div class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700/80">
                            <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ $label }}</h3>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                @foreach ($roleLabels as $roleCode => $roleLabel)
                                    <label class="checkbox-pill">
                                        <input type="checkbox" name="category_visibility[{{ $category }}][]" value="{{ $roleCode }}" @checked(in_array($roleCode, old("category_visibility.$category", $settings['category_visibility'][$category] ?? []), true))>
                                        <span>{{ $roleLabel }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Enregistrer la politique documentaire</button>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Annuler</a>
            </div>
        </form>
    </section>
@endsection

