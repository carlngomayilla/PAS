@php
    $unlockTarget = $target ?? null;
    $unlockRoute = $route ?? null;
    $unlockContext = $context ?? 'Modification demandee depuis la liste';
@endphp

@if ($unlockTarget && $unlockRoute)
    <form method="POST" action="{{ $unlockRoute }}" data-confirm-message="Transmettre une demande de deverrouillage au DG ?" data-confirm-tone="warning" data-confirm-label="Transmettre">
        @csrf
        <input type="hidden" name="reason" value="{{ $unlockContext }}">
        <button class="btn btn-secondary btn-sm" type="submit">Demander deverrouillage DG</button>
    </form>
@endif
