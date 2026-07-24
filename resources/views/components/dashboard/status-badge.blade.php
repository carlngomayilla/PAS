@props([
    'status' => 'info',
    'label' => null,
])

@php
    $tone = match ($status) {
        'success', 'ok', 'valide', 'validated' => ['var(--dashboard-success)', 'rgba(22, 132, 95, 0.12)'],
        'warning', 'attention', 'pending' => ['var(--dashboard-warning)', 'rgba(183, 121, 31, 0.12)'],
        'danger', 'late', 'retard', 'rejected' => ['var(--dashboard-danger)', 'rgba(180, 35, 24, 0.12)'],
        default => ['var(--dashboard-info)', 'rgba(15, 116, 144, 0.12)'],
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold']) }} style="color: {{ $tone[0] }}; background: {{ $tone[1] }};">
    {{ $label ?? $slot }}
</span>
