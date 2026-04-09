@props([
    'hasDraft' => false,
    'draftUpdatedAt' => null,
    'title' => 'Brouillon actif',
    'message' => 'Des modifications sont en attente de publication.',
    'publishRoute' => null,
    'discardRoute' => null,
])

@php
    $draftUpdatedAtLabel = null;

    if ($draftUpdatedAt) {
        $draftUpdatedAtLabel = \Illuminate\Support\Carbon::parse($draftUpdatedAt)->format('d/m/Y H:i');
    }
@endphp

@if ($hasDraft)
    <section {{ $attributes->merge(['class' => 'ui-card mb-3.5']) }}>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">{{ $title }}</p>
                <h2 class="mt-2">{{ $message }}</h2>
                <p class="mt-2 text-slate-600">
                    Le brouillon reste prive tant qu il n est pas publie.
                    @if ($draftUpdatedAtLabel)
                        Derniere mise a jour du brouillon : {{ $draftUpdatedAtLabel }}.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($publishRoute)
                    <form method="POST" action="{{ $publishRoute }}" class="m-0">
                        @csrf
                        <button class="btn btn-primary" type="submit">Publier le brouillon</button>
                    </form>
                @endif
                @if ($discardRoute)
                    <form method="POST" action="{{ $discardRoute }}" class="m-0">
                        @csrf
                        <button class="btn btn-secondary" type="submit">Supprimer le brouillon</button>
                    </form>
                @endif
            </div>
        </div>
    </section>
@endif
