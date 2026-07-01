@props([
    'level',
    'label',
    'rate' => null,
    'number' => null,
    'code' => null,
    'colspanLabel' => 7,
    'colspanValue' => 7,
])

@php
    $level = (string) $level;
    $rateLabel = $rate === null ? 'A parametrer' : number_format((float) $rate, 2, '.', ' ').'%';
    $classes = [
        'axis' => 'pta-level-axis',
        'strategic-objective' => 'pta-level-strategic-objective',
        'operational-objective' => 'pta-level-operational-objective',
        'sub-action' => 'pta-level-sub-action',
    ];
    $colors = [
        'axis' => ['background' => '#0f2f57', 'text' => '#ffffff'],
        'strategic-objective' => ['background' => '#1e5fa8', 'text' => '#ffffff'],
        'operational-objective' => ['background' => '#d8ecff', 'text' => '#0f2f57'],
        'sub-action' => ['background' => '#f1f5f9', 'text' => '#334155'],
    ][$level] ?? ['background' => '#f8fafc', 'text' => '#111827'];
    $cellStyle = 'background:'.$colors['background'].';color:'.$colors['text'].';';
    $rowClass = $classes[$level] ?? 'pta-level-action';
    $title = match ($level) {
        'axis' => 'Axe strategique',
        'strategic-objective' => 'Objectif strategique',
        'operational-objective' => 'Objectif operationnel',
        'sub-action' => 'Sous-action',
        default => 'Element',
    };
@endphp

<tr {{ $attributes->merge(['class' => $rowClass]) }}>
    <td class="pta-objective-number" style="{{ $cellStyle }}">{{ $number ?? $code ?? '' }}</td>
    <td colspan="{{ (int) $colspanLabel }}" class="pta-hierarchy-title" style="{{ $cellStyle }}">{{ $title }}</td>
    <td colspan="{{ (int) $colspanValue }}" class="pta-hierarchy-value" style="{{ $cellStyle }}">{{ $label }}</td>
    <td colspan="3" class="pta-hierarchy-rate" style="{{ $cellStyle }}">{{ $rateLabel }}</td>
</tr>
