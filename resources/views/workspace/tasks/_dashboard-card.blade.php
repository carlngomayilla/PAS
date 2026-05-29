@php
    $items = collect($items ?? []);
    $summary = is_array($summary ?? null) ? $summary : [];
    $components = collect($summary['components'] ?? []);
    $taskBadge = static fn (array $task): string => match ((string) ($task['criticality'] ?? 'normale')) {
        'critique' => 'anbg-badge anbg-badge-danger',
        'importante' => 'anbg-badge anbg-badge-warning',
        default => 'anbg-badge anbg-badge-info',
    };
@endphp

<section class="showcase-panel mb-4 app-screen-block" id="dashboard-personal-tasks">
    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <span class="showcase-eyebrow">Centre personnel</span>
            <h2 class="showcase-panel-title">Mes taches</h2>
        </div>

        <div class="showcase-action-row">
            <span class="showcase-chip">
                <span class="showcase-chip-dot {{ (int) ($summary['overdue'] ?? 0) > 0 ? 'bg-red-600' : 'bg-green-600' }}"></span>
                Score {{ number_format((float) ($summary['score'] ?? 100), 1, ',', ' ') }}%
            </span>
            <span class="showcase-chip">Qualite {{ $summary['quality_label'] ?? 'Excellent' }}</span>
            <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('workspace.tasks.index') }}">Ouvrir</a>
        </div>
    </div>

    <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
        <div class="rounded-2xl border border-slate-200/85 bg-white/95 p-3">
            <span class="text-xs font-bold uppercase text-[#667085]">Ouvertes</span>
            <strong class="mt-1 block text-2xl text-[#17324a]">{{ (int) ($summary['total'] ?? 0) }}</strong>
        </div>
        <div class="rounded-2xl border border-slate-200/85 bg-white/95 p-3">
            <span class="text-xs font-bold uppercase text-[#667085]">En retard</span>
            <strong class="mt-1 block text-2xl text-[#B42318]">{{ (int) ($summary['overdue'] ?? 0) }}</strong>
        </div>
        <div class="rounded-2xl border border-slate-200/85 bg-white/95 p-3">
            <span class="text-xs font-bold uppercase text-[#667085]">Critiques</span>
            <strong class="mt-1 block text-2xl text-[#F9B13C]">{{ (int) ($summary['critical'] ?? 0) }}</strong>
        </div>
    </div>

    @if ($components->isNotEmpty())
        <div class="mt-3 grid gap-2 [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
            @foreach ($components as $component)
                <div class="rounded-lg border border-slate-200/85 bg-white/90 p-3">
                    <span class="block text-[0.68rem] font-bold uppercase text-[#667085]">{{ $component['label'] ?? 'Composante' }}</span>
                    <div class="mt-1 flex items-baseline justify-between gap-2">
                        <strong class="text-sm text-[#17324a]">{{ number_format((float) ($component['score'] ?? 0), 1, ',', ' ') }}%</strong>
                        <span class="text-[0.68rem] font-bold text-[#667085]">{{ (int) ($component['weight'] ?? 0) }}%</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-4 space-y-2">
        @forelse ($items as $task)
            <article class="rounded-2xl border border-slate-200/85 bg-white/95 p-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <strong class="text-[#17324a]">{{ $task['title'] ?? 'Tache' }}</strong>
                            <span class="{{ $taskBadge($task) }} px-2 py-0.5 text-xs">{{ ucfirst((string) ($task['criticality'] ?? 'normale')) }}</span>
                            @if ((bool) ($task['is_overdue'] ?? false))
                                <span class="anbg-badge anbg-badge-danger px-2 py-0.5 text-xs">En retard</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm font-semibold text-[#17324a]">{{ $task['subject'] ?? '-' }}</p>
                        <p class="mt-1 text-xs text-[#667085]">{{ $task['context'] ?? '-' }} | {{ $task['remaining_label'] ?? 'Delai non defini' }}</p>
                    </div>

                    <a class="btn btn-secondary rounded-2xl px-3 py-2 text-xs" href="{{ $task['url'] ?? route('workspace.tasks.index') }}">Traiter</a>
                </div>
            </article>
        @empty
            <x-ui.empty-state
                title="Aucune tache ouverte"
                message="Les actions, validations et financements attendus apparaitront ici."
                icon="check"
                tone="success"
            />
        @endforelse
    </div>
</section>
