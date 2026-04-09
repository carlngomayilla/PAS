@extends('layouts.workspace')

@section('title', 'Apercu template')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Preview template</p>
                <h1 class="mt-2">{{ $template->name }}</h1>
                <p class="mt-2 text-slate-600">{{ $preview['label'] ?? 'Apercu' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.templates.show', $template) }}">Retour template</a>
                <a class="btn btn-primary" href="{{ route('workspace.super-admin.templates.edit', $template) }}">Modifier</a>
            </div>
        </div>
    </section>

    @if (($preview['type'] ?? null) === 'html')
        <section class="ui-card mb-3.5">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-950/50">
                {!! $preview['html'] ?? '' !!}
            </div>
        </section>
    @elseif (($preview['type'] ?? null) === 'excel')
        <section class="grid gap-4 xl:grid-cols-[0.95fr_1.05fr]">
            <article class="ui-card !mb-0">
                <h2>Structure classeur</h2>
                <div class="mt-4 space-y-3">
                    @foreach (($preview['sheets'] ?? []) as $sheet)
                        <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900/40">
                            <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $sheet['name'] }}</div>
                            <div class="mt-1 text-slate-500">{{ ($sheet['enabled'] ?? false) ? 'Active' : 'Inactive' }}</div>
                        </div>
                    @endforeach
                </div>
            </article>
            <article class="ui-card !mb-0">
                <h2>Metadonnees exportees</h2>
                <div class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-200">
                    <p><strong>Titre :</strong> {{ $preview['summary']['title'] ?? '-' }}</p>
                    <p><strong>Sous-titre :</strong> {{ $preview['summary']['subtitle'] ?? '-' }}</p>
                    <p><strong>Colonnes :</strong> {{ implode(', ', $preview['summary']['columns'] ?? []) ?: 'Aucune' }}</p>
                    <p><strong>Variables :</strong> {{ implode(', ', $preview['summary']['variables'] ?? []) ?: 'Aucune' }}</p>
                </div>
            </article>
        </section>
    @else
        <section class="ui-card mb-3.5">
            <h2>Resume configuration</h2>
            <div class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-200">
                @foreach (($preview['summary'] ?? []) as $key => $value)
                    <p>
                        <strong>{{ $key }}</strong> :
                        @if (is_array($value))
                            {{ implode(', ', array_keys(array_filter($value))) ?: 'Aucune donnee' }}
                        @else
                            {{ $value }}
                        @endif
                    </p>
                @endforeach
            </div>
        </section>
    @endif
@endsection

