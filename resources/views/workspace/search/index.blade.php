@extends('layouts.workspace')

@section('content')
    <div class="app-screen-flow">
        <section class="showcase-hero mb-4 app-screen-block">
            <div class="showcase-hero-body">
                <div class="max-w-3xl">
                    <span class="showcase-eyebrow">Recherche globale</span>
                    <h1 class="showcase-title">Résultats de recherche</h1>
                    <p class="showcase-subtitle">
                        Recherchez dans les actions, PAS, PAO, PTA, directions, services et utilisateurs accessibles.
                    </p>
                    <div class="showcase-chip-row">
                        <span class="showcase-chip">
                            <span class="showcase-chip-dot bg-[#3996d3]"></span>
                            {{ $total }} résultat(s)
                        </span>
                        @if ($query !== '')
                            <span class="showcase-chip">
                                <span class="showcase-chip-dot bg-[#8fc043]"></span>
                                « {{ $query }} »
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <section class="showcase-toolbar app-screen-block">
            <form method="GET" action="{{ route('workspace.search') }}" class="flex w-full flex-col gap-3 md:flex-row md:items-end">
                <div class="flex-1">
                    <label for="search-page-query" class="mb-1 block text-sm font-semibold text-slate-700">Recherche</label>
                    <input
                        id="search-page-query"
                        name="q"
                        type="search"
                        value="{{ $query }}"
                        class="w-full rounded-2xl border border-[#3996d3]/20 bg-white/90 px-4 py-3 text-sm text-slate-900 outline-none focus:border-[#3996d3] focus:ring-2 focus:ring-[#3996d3]/20"
                        placeholder="Action, direction, service, utilisateur, PAO..."
                        autofocus
                    >
                    <p class="mt-2 text-xs text-slate-500">Saisissez au moins 2 caractères pour lancer la recherche.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button class="btn btn-primary rounded-2xl px-5 py-3" type="submit">Rechercher</button>
                    <a class="btn btn-secondary rounded-2xl px-5 py-3" href="{{ route('workspace.search') }}">Réinitialiser</a>
                </div>
            </form>
        </section>

        @if (mb_strlen($query) < 2)
            <section class="showcase-panel app-screen-block">
                <p class="text-sm text-slate-600">
                    La recherche globale attend'un terme précis afin d’éviter les listes trop larges.
                </p>
            </section>
        @elseif ($total === 0)
            <section class="showcase-panel app-screen-block">
                <h2 class="showcase-panel-title">Aucun résultat</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Aucun élément ne correspond à votre recherche dans les données accessibles.
                </p>
            </section>
        @else
            @foreach ($groups as $group)
                @continue(count($group['items']) === 0)
                <section class="showcase-panel app-screen-block">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="showcase-panel-title">{{ $group['title'] }}</h2>
                            <p class="text-sm text-slate-600">{{ count($group['items']) }} résultat(s) trouvé(s)</p>
                        </div>
                    </div>

                    <div class="grid gap-3">
                        @foreach ($group['items'] as $item)
                            <a href="{{ $item['href'] }}" class="ui-card block rounded-2xl p-4 transition hover:-translate-y-0.5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-base font-black text-slate-950">{{ $item['title'] }}</p>
                                        <p class="mt-1 text-sm font-semibold text-[#3996d3]">{{ $item['subtitle'] }}</p>
                                        <p class="mt-1 text-sm text-slate-600">{{ $item['meta'] }}</p>
                                        @if (! empty($item['details']) && is_array($item['details']))
                                            <dl class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                @foreach ($item['details'] as $detail)
                                                    <div class="rounded-xl border border-slate-200/80 bg-slate-50/80 px-3 py-2">
                                                        <dt class="text-[11px] font-bold uppercase tracking-wide text-slate-500">{{ $detail['label'] ?? '' }}</dt>
                                                        <dd class="mt-0.5 break-words text-sm font-semibold text-slate-800">{{ $detail['value'] ?? '-' }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if (! empty($item['badge']))
                                            <span class="anbg-badge anbg-badge-{{ $item['badge_tone'] ?? 'neutral' }} px-3 py-1 text-[11px]">{{ $item['badge'] }}</span>
                                        @endif
                                        <span class="anbg-badge anbg-badge-info px-3 py-1 text-[11px]">Ouvrir</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </div>
@endsection
