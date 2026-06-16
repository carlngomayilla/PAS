@extends('layouts.workspace')

@section('title', 'Mes taches')

@section('content')
    @php
        $items = collect($personalTasks['items'] ?? []);
        $summary = is_array($personalTasks['summary'] ?? null) ? $personalTasks['summary'] : [];
        $components = collect($summary['components'] ?? []);
        $badge = static fn (array $task): string => match ((string) ($task['criticality'] ?? 'normale')) {
            'critique' => 'anbg-badge anbg-badge-danger',
            'importante' => 'anbg-badge anbg-badge-warning',
            default => 'anbg-badge anbg-badge-info',
        };
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title
            class="mb-4 app-screen-block"
            eyebrow="Centre personnel"
            title="Mes taches"
        >
            <x-slot:actions>
                <span class="showcase-chip">
                    <span class="showcase-chip-dot {{ (int) ($summary['overdue'] ?? 0) > 0 ? 'bg-red-600' : 'bg-green-600' }}"></span>
                    {{ (int) ($summary['total'] ?? 0) }} ouverte(s)
                </span>
                <span class="showcase-chip">
                    Score {{ number_format((float) ($summary['score'] ?? 100), 0, ',', ' ') }}%
                </span>
                <span class="showcase-chip">Qualite {{ $summary['quality_label'] ?? 'Excellent' }}</span>
            </x-slot:actions>
        </x-ui.page-title>

        <section class="showcase-panel app-screen-block">
            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))]">
                <article class="rounded-2xl border border-slate-200/85 bg-white/95 p-4">
                    <span class="text-xs font-bold uppercase text-[#667085]">Taches ouvertes</span>
                    <strong class="mt-1 block text-2xl text-[#17324a]">{{ (int) ($summary['total'] ?? 0) }}</strong>
                </article>
                <article class="rounded-2xl border border-slate-200/85 bg-white/95 p-4">
                    <span class="text-xs font-bold uppercase text-[#667085]">En retard</span>
                    <strong class="mt-1 block text-2xl text-[#B42318]">{{ (int) ($summary['overdue'] ?? 0) }}</strong>
                </article>
                <article class="rounded-2xl border border-slate-200/85 bg-white/95 p-4">
                    <span class="text-xs font-bold uppercase text-[#667085]">Sous 24h</span>
                    <strong class="mt-1 block text-2xl text-[#F9B13C]">{{ (int) ($summary['due_soon'] ?? 0) }}</strong>
                </article>
                <article class="rounded-2xl border border-slate-200/85 bg-white/95 p-4">
                    <span class="text-xs font-bold uppercase text-[#667085]">Critiques</span>
                    <strong class="mt-1 block text-2xl text-[#3996D3]">{{ (int) ($summary['critical'] ?? 0) }}</strong>
                </article>
            </div>

            @if ($components->isNotEmpty())
                <div class="mt-5">
                    <h2 class="text-sm font-bold uppercase text-[#667085]">Composantes du score personnel</h2>
                    <div class="mt-3 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
                        @foreach ($components as $component)
                            <article class="rounded-lg border border-slate-200/85 bg-white/95 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <span class="text-xs font-bold uppercase text-[#667085]">{{ $component['label'] ?? 'Composante' }}</span>
                                    <span class="anbg-badge anbg-badge-info px-2 py-0.5 text-xs">{{ (int) ($component['weight'] ?? 0) }}%</span>
                                </div>
                                <strong class="mt-2 block text-2xl text-[#17324a]">{{ number_format((float) ($component['score'] ?? 0), 0, ',', ' ') }}%</strong>
                            </article>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="mt-5 space-y-3">
                @forelse ($items as $task)
                    <article class="rounded-2xl border border-slate-200/85 bg-white/95 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="text-[#17324a]">{{ $task['title'] ?? 'Tache' }}</strong>
                                    <span class="{{ $badge($task) }} px-2 py-0.5 text-xs">{{ ucfirst((string) ($task['criticality'] ?? 'normale')) }}</span>
                                    <span class="{{ (bool) ($task['is_overdue'] ?? false) ? 'anbg-badge anbg-badge-danger' : 'anbg-badge anbg-badge-info' }} px-2 py-0.5 text-xs">
                                        {{ (bool) ($task['is_overdue'] ?? false) ? 'En retard' : 'Ouverte' }}
                                    </span>
                                </div>

                                <h2 class="mt-2 text-lg font-bold text-[#17324a]">{{ $task['subject'] ?? '-' }}</h2>
                                <p class="mt-1 text-sm text-[#667085]">{{ $task['context'] ?? '-' }}</p>

                                <dl class="mt-3 grid gap-3 text-sm [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
                                    <div>
                                        <dt class="text-xs font-bold uppercase text-[#667085]">Responsable</dt>
                                        <dd class="font-semibold text-[#17324a]">{{ $task['responsible'] ?? '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold uppercase text-[#667085]">Reception</dt>
                                        <dd class="font-semibold text-[#17324a]">{{ optional($task['received_at'] ?? null)->format('d/m/Y H:i') ?: '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold uppercase text-[#667085]">Delai</dt>
                                        <dd class="font-semibold text-[#17324a]">{{ optional($task['deadline_at'] ?? null)->format('d/m/Y H:i') ?: '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold uppercase text-[#667085]">Impact score</dt>
                                        <dd class="font-semibold text-[#17324a]">{{ $task['score_impact'] ?? '-' }}</dd>
                                    </div>
                                </dl>
                            </div>

                            @if (! empty($task['can_validate']) && ! empty($task['action_id']))
                                {{-- A42 — Validation deplacee dans Mes taches : acte direct (cf. actions.review). --}}
                                <div class="flex w-full flex-col items-stretch gap-2 sm:w-56">
                                    <form method="POST" action="{{ route('workspace.actions.review', $task['action_id']) }}">
                                        @csrf
                                        <input type="hidden" name="decision" value="valider">
                                        @if (! empty($task['sous_action_id']))
                                            <input type="hidden" name="sous_action_id" value="{{ $task['sous_action_id'] }}">
                                        @endif
                                        <button type="submit" class="btn btn-primary w-full rounded-2xl px-4 py-2.5">Valider</button>
                                    </form>

                                    <details class="rounded-2xl border border-slate-200/85 bg-white/95">
                                        <summary class="cursor-pointer rounded-2xl px-4 py-2.5 text-center text-sm font-semibold text-[#B42318]">Renvoyer pour correction</summary>
                                        <form method="POST" action="{{ route('workspace.actions.review', $task['action_id']) }}" class="space-y-2 p-3">
                                            @csrf
                                            <input type="hidden" name="decision" value="rejeter">
                                            @if (! empty($task['sous_action_id']))
                                                <input type="hidden" name="sous_action_id" value="{{ $task['sous_action_id'] }}">
                                            @endif
                                            <textarea name="motif" rows="2" required placeholder="Motif du renvoi (obligatoire)" class="w-full rounded-lg border border-slate-300 p-2 text-sm"></textarea>
                                            <button type="submit" class="btn w-full rounded-2xl px-4 py-2 text-white" style="background:#B42318;">Confirmer le renvoi</button>
                                        </form>
                                    </details>

                                    <a class="text-center text-xs text-[#3996D3] underline" href="{{ $task['url'] ?? route('dashboard') }}">Ouvrir la fiche</a>
                                </div>
                            @else
                                <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ $task['url'] ?? route('dashboard') }}">Traiter</a>
                            @endif
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state
                        title="Aucune tache ouverte"
                        message="Les actions, validations, corrections et financements attendus dans votre perimetre apparaitront ici."
                        icon="check"
                        tone="success"
                    />
                @endforelse
            </div>
        </section>
    </div>
@endsection
