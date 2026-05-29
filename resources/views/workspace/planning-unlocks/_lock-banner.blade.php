@php
    $unlockTarget = $target ?? null;
    $unlockRoute = $route ?? null;
    $unlockService = app(\App\Services\PlanningModificationLockService::class);
    $canAskUnlock = $unlockTarget && auth()->check() && $unlockService->canRequestUnlock(auth()->user(), $unlockTarget);
@endphp

@if ($unlockTarget && $unlockService->isLocked($unlockTarget))
    <section class="showcase-panel mb-4 border border-amber-200 bg-amber-50 app-screen-block">
        <h2 class="showcase-panel-title text-amber-950">Enregistrement verrouille</h2>
        <p class="mt-2 text-sm font-medium text-amber-900">
            Cet element est verrouille apres enregistrement. Une modification exige une demande approuvee par le DG.
        </p>
        @if ($canAskUnlock && $unlockRoute)
            <form method="POST" action="{{ $unlockRoute }}" class="mt-4 grid gap-3 md:max-w-2xl" data-confirm-message="Transmettre une demande de deverrouillage au DG ?" data-confirm-tone="warning" data-confirm-label="Transmettre">
                @csrf
                <label for="unlock_reason_{{ $unlockTarget->getKey() }}">Motif de la demande</label>
                <textarea id="unlock_reason_{{ $unlockTarget->getKey() }}" name="reason" rows="3" required minlength="5" placeholder="Expliquez la correction ou la mise a jour demandee.">{{ old('reason') }}</textarea>
                <button class="btn btn-primary justify-self-start" type="submit">Demander deverrouillage DG</button>
            </form>
        @endif
    </section>
@endif
