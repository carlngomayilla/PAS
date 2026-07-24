@props([
    'rows' => 3,
])

<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
    @for ($i = 0; $i < (int) $rows; $i++)
        <div class="h-16 animate-pulse rounded-[var(--dashboard-card-radius)] bg-[var(--dashboard-surface-muted)]"></div>
    @endfor
</div>
