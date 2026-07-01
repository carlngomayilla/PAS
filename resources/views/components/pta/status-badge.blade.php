@props([
    'status' => 'en_cours',
    'label' => null,
    'type' => 'action',
])

@php
    $status = (string) $status;
    $type = (string) $type;
    $label = $label ?? \Illuminate\Support\Str::headline($status);
    $styles = [
        'action' => [
            'a_parametrer' => 'background:#6b7280;color:#fff;',
            'en_attente' => 'background:#e5e7eb;color:#111827;',
            'en_cours' => 'background:#3996d3;color:#fff;',
            'realise' => 'background:#00b050;color:#fff;',
            'en_retard' => 'background:#ff0000;color:#fff;',
        ],
        'suivi' => [
            'a_parametrer' => 'background:#6b7280;color:#fff;',
            'non_demarre' => 'background:#e5e7eb;color:#111827;',
            'en_cours' => 'background:#3996d3;color:#fff;',
            'validation_chef' => 'background:#9333ea;color:#fff;',
            'validation_controleur' => 'background:#4f46e5;color:#fff;',
            'cloture' => 'background:#00b050;color:#fff;',
        ],
        'delai' => [
            'dans_les_delais' => 'background:#00b050;color:#fff;',
            'hors_delai' => 'background:#f97316;color:#111827;',
        ],
        'alerte' => [
            'aucune_alerte' => 'background:#d9ead3;color:#14532d;',
            'echeance_proche' => 'background:#fff200;color:#111827;',
            'critique' => 'background:#f9b13c;color:#111827;',
            'en_retard' => 'background:#ff0000;color:#fff;',
            'cloturee' => 'background:#00b050;color:#fff;',
            'a_parametrer' => 'background:#6b7280;color:#fff;',
        ],
        'preuve' => [
            'preuves_dans_delais' => 'background:#d9ead3;color:#14532d;',
            'preuves_hors_delai' => 'background:#f9b13c;color:#111827;',
            'preuves_non_livrees' => 'background:#fee2e2;color:#991b1b;',
            'en_attente' => 'background:#e5e7eb;color:#111827;',
        ],
    ];
    $style = $styles[$type][$status] ?? 'background:#e5e7eb;color:#111827;';
@endphp

<span {{ $attributes->merge(['class' => 'pta-status-badge']) }} style="{{ $style }}">
    {{ $label }}
</span>
