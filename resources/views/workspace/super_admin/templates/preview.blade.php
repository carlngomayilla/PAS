@extends('layouts.workspace')

@section('title', 'Aperçu template')

@section('content')
    <section class="showcase-panel mb-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Preview template</p>
                <h1 class="mt-2">{{ $template->name }}</h1>
                <p class="mt-2 text-slate-600">{{ $preview['label'] ?? 'Aperçu' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.templates.show', $template) }}">Retour template</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.templates.edit', $template) }}">Modifier</a>
            </div>
        </div>
    </section>

    @if (($preview['type'] ?? null) === 'html')
        <section class="showcase-panel mb-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                {{-- A34 — XSS preview : `$preview['html']` provient d un template
                     configurable par le super_admin. On filtre via strip_tags avec
                     une whitelist typographique stricte avant rendu. Les balises
                     interactives (script, iframe, on*, style, link, object) sont
                     supprimees pour empecher l execution de code malveillant.
                     Cette voie reste accessible uniquement aux super_admin
                     (cf. route /workspace/super-admin/templates/...). --}}
                {!! strip_tags((string) ($preview['html'] ?? ''), [
                    'p', 'br', 'strong', 'em', 'b', 'i', 'u', 'span',
                    'ul', 'ol', 'li', 'dl', 'dt', 'dd',
                    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                    'table', 'thead', 'tbody', 'tr', 'th', 'td',
                    'blockquote', 'pre', 'code', 'hr', 'small', 'sub', 'sup',
                    'div', 'section', 'article', 'header', 'footer',
                ]) !!}
            </div>
        </section>
    @elseif (($preview['type'] ?? null) === 'excel')
        <section class="grid gap-4 xl:grid-cols-[0.95fr_1.05fr]">
            <article class="ui-card !mb-0">
                <h2>Structure classeur</h2>
                <div class="mt-4 space-y-3">
                    @foreach (($preview['sheets'] ?? []) as $sheet)
                        <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-3 text-sm">
                            <div class="font-semibold text-slate-900">{{ $sheet['name'] }}</div>
                            <div class="mt-1 text-slate-500">{{ ($sheet['enabled'] ?? false) ? 'Active' : 'Inactive' }}</div>
                        </div>
                    @endforeach
                </div>
            </article>
            <article class="ui-card !mb-0">
                <h2>Métadonnées exportées</h2>
                <div class="mt-4 space-y-3 text-sm text-slate-700">
                    <p><strong>Titre :</strong> {{ $preview['summary']['title'] ?? '-' }}</p>
                    <p><strong>Sous-titre :</strong> {{ $preview['summary']['subtitle'] ?? '-' }}</p>
                    <p><strong>Colonnes :</strong> {{ implode(', ', $preview['summary']['columns'] ?? []) ?: 'Aucune' }}</p>
                    <p><strong>Variables :</strong> {{ implode(', ', $preview['summary']['variables'] ?? []) ?: 'Aucune' }}</p>
                </div>
            </article>
        </section>
    @else
        <section class="showcase-panel mb-4">
            <h2>Resume configuration</h2>
            <div class="mt-4 space-y-3 text-sm text-slate-700">
                @foreach (($preview['summary'] ?? []) as $key => $value)
                    <p>
                        <strong>{{ $key }}</strong> :
                        @if (is_array($value))
                            {{ implode(', ', array_keys(array_filter($value))) ?: 'Aucune donnée' }}
                        @else
                            {{ $value }}
                        @endif
                    </p>
                @endforeach
            </div>
        </section>
    @endif
@endsection
