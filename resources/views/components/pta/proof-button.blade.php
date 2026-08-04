@props([
    'hasProof' => false,
    'previewUrl' => '#',
    'downloadUrl' => '#',
    'title' => 'Piece justificative',
    'subtitle' => 'Preuve de traitement',
    'mime' => '',
    'count' => 0,
    'exportMode' => 'web',
])

@php
    $hasProof = (bool) $hasProof;
    $isInteractive = (string) $exportMode === 'web';
    $previewUrl = trim((string) $previewUrl);
    $downloadUrl = trim((string) $downloadUrl);
    $proofCount = (int) $count;
@endphp

@if ($hasProof && $isInteractive && $previewUrl !== '' && $previewUrl !== '#')
    <a
        href="{{ $previewUrl }}"
        data-preview-file
        data-preview-title="{{ $title }}"
        data-preview-subtitle="{{ $subtitle }}"
        data-preview-mime="{{ $mime }}"
        data-preview-url="{{ $previewUrl }}"
        @if ($downloadUrl !== '' && $downloadUrl !== '#') data-download-url="{{ $downloadUrl }}" @endif
        aria-label="Previsualiser la preuve : {{ $title }}"
        title="Previsualiser la preuve"
        {{ $attributes->merge(['class' => 'pta-proof-button']) }}
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
            <circle cx="12" cy="12" r="3" />
        </svg>
        <span class="pta-proof-button-label">Preuve</span>
        <span class="pta-proof-count">{{ $proofCount }}</span>
    </a>
@elseif ($hasProof)
    <span
        aria-label="Preuve disponible : {{ $title }}"
        {{ $attributes->merge(['class' => 'pta-proof-button pta-proof-button-readonly']) }}
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
            <circle cx="12" cy="12" r="3" />
        </svg>
        <span class="pta-proof-button-label">Preuve</span>
        <span class="pta-proof-count">{{ $proofCount }}</span>
    </span>
@else
    <button
        type="button"
        {{ $attributes->merge(['class' => 'pta-proof-button pta-proof-button-empty']) }}
        disabled
        aria-disabled="true"
        aria-label="Aucune preuve deposee"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
            <circle cx="12" cy="12" r="3" />
            <path d="M4 4l16 16" />
        </svg>
        <span class="pta-proof-button-label">À déposer</span>
    </button>
@endif
