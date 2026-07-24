@props([
    'href',
    'label' => 'Ouvrir',
])

<a {{ $attributes->merge(['class' => 'inline-flex items-center justify-center rounded-md border border-[var(--dashboard-border)] px-3 py-2 text-sm font-semibold text-[var(--dashboard-text)] transition hover:border-[var(--dashboard-accent)] hover:text-[var(--dashboard-accent)]']) }} href="{{ $href }}">
    {{ $label }}
    <span class="ml-2" aria-hidden="true">-></span>
</a>
