@props([
    'title' => 'Aucune donnee',
    'message' => null,
])

<div {{ $attributes->merge(['class' => 'rounded-[var(--dashboard-card-radius)] border border-dashed border-[var(--dashboard-border)] bg-[var(--dashboard-surface)] p-5 text-center']) }}>
    <p class="text-sm font-semibold text-[var(--dashboard-text)]">{{ $title }}</p>
    @if ($message)
        <p class="mt-1 text-xs text-[var(--dashboard-muted)]">{{ $message }}</p>
    @endif
</div>
