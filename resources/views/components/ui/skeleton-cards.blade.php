@props([
    'count'   => 3,
    'columns' => 3,
])

<div style="display: grid; grid-template-columns: repeat({{ (int) $columns }}, 1fr); gap: 1rem;" aria-busy="true" aria-label="Chargement en cours...">
    @for ($i = 0; $i < (int) $count; $i++)
        <div class="skeleton-card" style="opacity: {{ 1 - $i * 0.08 }};">
            <div class="skeleton-card-header">
                <div class="skeleton skeleton-avatar"></div>
                <div style="flex:1; display:flex; flex-direction:column; gap:0.4rem;">
                    <div class="skeleton skeleton-title"></div>
                    <div class="skeleton skeleton-text-sm"></div>
                </div>
            </div>
            <div class="skeleton skeleton-text"></div>
            <div class="skeleton skeleton-text" style="width:75%;"></div>
            <div class="skeleton skeleton-badge"></div>
        </div>
    @endfor
</div>
