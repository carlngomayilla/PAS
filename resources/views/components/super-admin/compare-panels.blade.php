@props([
    'publishedTitle' => 'Version publiee',
    'editableTitle' => 'Version en edition',
    'sectionClass' => 'grid gap-4 xl:grid-cols-2',
    'publishedPanelClass' => 'ui-card !mb-0',
    'editablePanelClass' => 'ui-card !mb-0',
])

<section {{ $attributes->merge(['class' => $sectionClass]) }}>
    <article class="{{ $publishedPanelClass }}">
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $publishedTitle }}</p>
        <div class="mt-4">
            {{ $published }}
        </div>
    </article>

    <article class="{{ $editablePanelClass }}">
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $editableTitle }}</p>
        <div class="mt-4">
            {{ $editable }}
        </div>
    </article>
</section>
