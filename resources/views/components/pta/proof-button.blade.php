@props([
    'hasProof' => false,
    'url' => '#',
    'count' => 0,
    'exportMode' => 'web',
])

@php
    $hasProof = (bool) $hasProof;
    $isInteractive = (string) $exportMode === 'web';
@endphp

@if ($hasProof && $isInteractive)
    <button
        type="button"
        {{ $attributes->merge(['class' => 'pta-proof-button']) }}
        data-pta-action-open
        data-url="{{ $url }}"
    >
        Visualiser la preuve
        <span>{{ (int) $count }}</span>
    </button>
@elseif ($hasProof)
    <span {{ $attributes->merge(['class' => 'pta-proof-button pta-proof-button-readonly']) }}>
        Preuve disponible
        <span>{{ (int) $count }}</span>
    </span>
@else
    <button
        type="button"
        {{ $attributes->merge(['class' => 'pta-proof-button pta-proof-button-empty']) }}
        disabled
        aria-disabled="true"
    >
        Aucune preuve
    </button>
@endif
