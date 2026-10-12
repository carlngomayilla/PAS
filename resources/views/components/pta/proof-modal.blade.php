<div {{ $attributes->merge(['class' => 'pta-suivi-modal-backdrop']) }} data-pta-modal aria-hidden="true" inert>
    <div class="pta-suivi-modal" role="dialog" aria-modal="true" aria-labelledby="pta-suivi-modal-title" aria-describedby="pta-suivi-modal-description" tabindex="-1">
        <div class="pta-suivi-modal-head">
            <div>
                <p>Previsualisation PTA</p>
                <h2 id="pta-suivi-modal-title">Détail de l'action et des preuves</h2>
                <p id="pta-suivi-modal-description" class="pta-suivi-modal-description">Consultez le parcours, les validations et les pièces jointes de l’action sélectionnée.</p>
            </div>
            <button type="button" class="pta-suivi-modal-close" data-pta-modal-close aria-label="Fermer le détail de l’action">Fermer</button>
        </div>
        <div class="pta-suivi-modal-body" data-pta-modal-body aria-live="polite"></div>
    </div>
</div>
