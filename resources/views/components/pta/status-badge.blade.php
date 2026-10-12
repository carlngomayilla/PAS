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
            'en_cours' => 'background:#176a9d;color:#fff;',
            'realise' => 'background:#087f45;color:#fff;',
            'en_retard' => 'background:#b42318;color:#fff;',
        ],
        'suivi' => [
            'a_parametrer' => 'background:#6b7280;color:#fff;',
            'non_demarre' => 'background:#e5e7eb;color:#111827;',
            'en_cours' => 'background:#176a9d;color:#fff;',
            'validation_chef' => 'background:#6d28d9;color:#fff;',
            'validation_controleur' => 'background:#3730a3;color:#fff;',
            'cloture' => 'background:#087f45;color:#fff;',
        ],
        'delai' => [
            'dans_les_delais' => 'background:#087f45;color:#fff;',
            'hors_delai' => 'background:#9a5b00;color:#fff;',
        ],
        'alerte' => [
            'aucune_alerte' => 'background:#d9ead3;color:#14532d;',
            'echeance_proche' => 'background:#8a5b00;color:#fff;',
            'critique' => 'background:#9a5b00;color:#fff;',
            'en_retard' => 'background:#b42318;color:#fff;',
            'cloturee' => 'background:#087f45;color:#fff;',
            'a_parametrer' => 'background:#6b7280;color:#fff;',
        ],
        'preuve' => [
            'preuves_dans_delais' => 'background:#d9ead3;color:#14532d;',
            'preuves_hors_delai' => 'background:#9a5b00;color:#fff;',
            'preuves_non_livrees' => 'background:#fee2e2;color:#991b1b;',
            'en_attente' => 'background:#e5e7eb;color:#111827;',
        ],
    ];
    $style = $styles[$type][$status] ?? 'background:#e5e7eb;color:#111827;';
@endphp

<span {{ $attributes->merge(['class' => 'pta-status-badge pta-status-badge--'.$type.' pta-status-badge--'.str_replace('_', '-', $status)]) }} style="{{ $style }}">
    {{ $label }}
</span>
