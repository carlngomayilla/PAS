@php
    $scopeLabel = trim((string) ($scopeLabel ?? ''));
    $scopeValue = trim((string) ($scopeValue ?? ''));
    $directionLabel = trim((string) ($directionLabel ?? ''));
    $directionResponsable = trim((string) ($directionResponsable ?? ''));
    $serviceLabel = trim((string) ($serviceLabel ?? ''));
    $serviceResponsable = trim((string) ($serviceResponsable ?? ''));
    $tableTitle = trim((string) ($tableTitle ?? 'Tableau'));
    $periodLabel = trim((string) ($periodLabel ?? ''));
    $generatedAtLabel = trim((string) ($generatedAtLabel ?? ''));
@endphp

<div class="report-context">
    @if ($scopeLabel !== '' && $scopeValue !== '')
        <p class="report-context-line">
            <span class="report-context-label">{{ mb_strtoupper($scopeLabel, 'UTF-8') }} :</span>
            {{ $scopeValue }}
        </p>
    @endif

    @if ($directionLabel !== '')
        <p class="report-context-line">
            <span class="report-context-label">DIRECTION :</span>
            {{ $directionLabel }}
        </p>
    @endif

    @if ($directionResponsable !== '')
        <p class="report-context-line">
            <span class="report-context-label">RESPONSABLE DIRECTION :</span>
            {{ $directionResponsable }}
        </p>
    @endif

    @if ($serviceLabel !== '')
        <p class="report-context-line report-context-service">
            <span class="report-context-label">SERVICE :</span>
            {{ $serviceLabel }}
        </p>
    @endif

    @if ($serviceResponsable !== '')
        <p class="report-context-line report-context-service">
            <span class="report-context-label">RESPONSABLE SERVICE :</span>
            {{ $serviceResponsable }}
        </p>
    @endif

    <p class="report-context-line">
        <span class="report-context-label">TABLEAU :</span>
        <span class="report-context-table">{{ $tableTitle }}</span>
    </p>

    @if ($periodLabel !== '')
        <p class="report-context-line">
            <span class="report-context-label">PÉRIODE :</span>
            {{ $periodLabel }}
        </p>
    @endif

    @if ($generatedAtLabel !== '')
        <p class="report-context-line">
            <span class="report-context-label">DATE DE GÉNÉRATION :</span>
            {{ $generatedAtLabel }}
        </p>
    @endif
</div>
