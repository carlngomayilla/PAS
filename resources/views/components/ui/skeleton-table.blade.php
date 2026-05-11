@props([
    'rows'    => 4,
    'columns' => 4,
])

<div class="skeleton-table-wrap" aria-busy="true" aria-label="Chargement en cours...">
    @for ($r = 0; $r < (int) $rows; $r++)
        <div class="skeleton-row">
            @for ($c = 0; $c < (int) $columns; $c++)
                <div class="skeleton skeleton-text" style="width: {{ $c === 0 ? '40%' : ($c === (int)$columns - 1 ? '8%' : '18%') }}; opacity: {{ 1 - $r * 0.12 }};"></div>
            @endfor
        </div>
    @endfor
</div>
