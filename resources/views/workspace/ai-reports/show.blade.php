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
                @if ($report->contentForExport() !== '')
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

        <form method="POST" action="{{ route('workspace.ai-reports.update', $report) }}" class="rounded-lg border border-[#d8ecf8] bg-white p-4">
            @csrf
            @method('PATCH')
            <label class="form-label" for="title">Titre</label>
            <input id="title" name="title" value="{{ old('title', $report->title) }}" required class="mb-4 w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
            <label class="form-label" for="content">Contenu</label>
            <textarea id="content" name="content" rows="22" required class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm font-mono">{{ $content }}</textarea>
            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <button class="btn btn-outline" type="submit">Enregistrer</button>
            </div>
        </form>

        <form method="POST" action="{{ route('workspace.ai-reports.validate', $report) }}" class="mt-4 rounded-lg border border-green-200 bg-green-50 p-4">
            @csrf
            <textarea name="content" class="hidden">{{ $content }}</textarea>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm font-semibold text-green-800">Validation humaine requise avant export officiel.</p>
                <button class="btn btn-primary" type="submit">Valider le rapport</button>
            </div>
        </form>

        <details class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
            <summary class="cursor-pointer text-sm font-bold text-slate-700">Snapshot metriques</summary>
            <pre class="mt-3 overflow-x-auto text-xs text-slate-600">{{ json_encode($report->metrics_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    </section>
</div>
@endsection
