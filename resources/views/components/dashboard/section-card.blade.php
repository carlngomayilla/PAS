@props([
    'title' => null,
    'subtitle' => null,
])

<section {{ $attributes->merge(['class' => 'rounded-[var(--dashboard-card-radius)] border border-[var(--dashboard-border)] bg-[var(--dashboard-surface)] p-4 shadow-[var(--dashboard-card-shadow)]']) }}>
    @if ($title || $subtitle)
        <div class="mb-4 flex flex-col gap-1">
            @if ($title)
                <h2 class="text-base font-semibold text-[var(--dashboard-text)]">{{ $title }}</h2>
            @endif
            @if ($subtitle)
                <p class="text-sm text-[var(--dashboard-muted)]">{{ $subtitle }}</p>
            @endif
        </div>
    @endif

    {{ $slot }}
</section>
