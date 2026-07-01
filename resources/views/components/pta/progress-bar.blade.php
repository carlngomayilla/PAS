@props([
    'value' => 0,
    'label' => null,
])

@php
    $raw = max(0.0, (float) $value);
    $display = min(100.0, $raw);
    $label = $label ?? number_format($raw, 2, '.', ' ').'%';
@endphp

<div {{ $attributes->merge(['class' => 'pta-progress']) }}>
    <div class="pta-progress-track" aria-hidden="true">
        <span class="pta-progress-fill" style="width:{{ $display }}%;"></span>
    </div>
    <span class="pta-progress-label">{{ $label }}</span>
</div>
