@props([
    'url' => '#',
    'exportMode' => 'web',
])

@php
    $url = trim((string) $url);
    $isInteractive = (string) $exportMode === 'web' && $url !== '' && $url !== '#';
@endphp

@if ($isInteractive)
    <a
        href="{{ $url }}"
        data-pta-action-open
        data-url="{{ $url }}"
        {{ $attributes->merge(['class' => 'pta-preview-link']) }}
    >
        {{ $slot }}
    </a>
@else
    {{ $slot }}
@endif
