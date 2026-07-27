@extends('layouts.workspace')

@section('content')
@php($content = old('content', $report->validated_content ?: $report->ai_draft))
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">{{ $report->title }}</h1>
                <p class="text-sm text-slate-500">{{ $types[$report->report_type] ?? $report->report_type }} · {{ $report->status }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-outline" href="{{ route('workspace.ai-reports.index') }}">Retour</a>
                @if ($report->contentForExport() !== '' && $report->isTemplateConforming() && in_array($report->status, [\App\Models\AiGeneratedReport::STATUS_VALIDATED, \App\Models\AiGeneratedReport::STATUS_EXPORTED], true))
                    <a class="btn btn-secondary" href="{{ route('workspace.ai-reports.export.pdf', $report) }}">PDF</a>
                    <a class="btn btn-secondary" href="{{ route('workspace.ai-reports.export.word', $report) }}">Word</a>
                    <a class="btn btn-secondary" href="{{ route('workspace.ai-reports.export.excel', $report) }}">Excel</a>
                @endif
            </div>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
        @endif

        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                <p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Fournisseur IA</p>
                <p class="mt-1 font-extrabold text-slate-900 dark:text-white">{{ $report->ai_provider === 'openai' ? 'OpenAI' : 'Non renseigné' }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $report->ai_model ?: 'Modèle non renseigné' }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                <p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Modèle institutionnel</p>
                <p class="mt-1 font-extrabold text-slate-900 dark:text-white">{{ $report->template_code ?: 'À contrôler' }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Version {{ $report->template_version ?: 'non renseignée' }}</p>
            </div>
            <div class="rounded-lg border p-3 {{ $report->isTemplateConforming() ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/40' : 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40' }}">
                <p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Conformité</p>
                <p class="mt-1 font-extrabold text-slate-900 dark:text-white">{{ $report->conformity_score }} %</p>
                <p class="text-xs text-slate-600 dark:text-slate-300">{{ $report->isTemplateConforming() ? 'Conforme au modèle' : 'Correction requise' }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                <p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Contrôle</p>
                <p class="mt-1 font-extrabold text-slate-900 dark:text-white">{{ $report->conformity_checked_at?->format('d/m/Y H:i') ?: 'En attente' }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Recalculé après chaque modification</p>
            </div>
        </div>

        @if (! $report->isTemplateConforming() && ! empty($report->conformity_issues))
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                <p class="font-extrabold">Export officiel bloqué</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($report->conformity_issues as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('workspace.ai-reports.update', $report) }}" class="rounded-lg border border-[#d8ecf8] bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
            @csrf
            @method('PATCH')
            <label class="form-label" for="title">Titre</label>
            <input id="title" name="title" value="{{ old('title', $report->title) }}" required class="mb-4 w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-white">
            <label class="form-label" for="content">Contenu</label>
            <textarea id="content" name="content" rows="22" required class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm font-mono dark:border-slate-700 dark:bg-slate-950 dark:text-white">{{ $content }}</textarea>
            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <button class="btn btn-outline" type="submit">Enregistrer</button>
            </div>
        </form>

        <form method="POST" action="{{ route('workspace.ai-reports.validate', $report) }}" class="mt-4 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950/40">
            @csrf
            <textarea name="content" class="hidden">{{ $content }}</textarea>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm font-semibold text-green-800 dark:text-green-100">Validation humaine requise après le contrôle automatique du modèle.</p>
                <button class="btn btn-primary" type="submit" @disabled(! $report->isTemplateConforming())>Valider le rapport</button>
            </div>
        </form>

        @if ($wordPreview !== null)
            @include('workspace.ai-reports.partials.pta-quarterly-preview', ['wordPreview' => $wordPreview])
        @endif

        <details class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
            <summary class="cursor-pointer text-sm font-bold text-slate-700">Snapshot metriques</summary>
            <pre class="mt-3 overflow-x-auto text-xs text-slate-600">{{ json_encode($report->metrics_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    </section>
</div>
@endsection
