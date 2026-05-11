@props([
    'title'   => 'Aucune donnée',
    'message' => 'Aucun élément ne correspond au périmètre courant.',
    'icon'    => 'inbox',
    'tone'    => 'neutral',
])

@php
$iconPaths = [
    'inbox'  => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
    'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
    'chart'  => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'file'   => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
    'check'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    'lock'   => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
];

$toneColors = [
    'neutral' => ['bg' => 'rgba(148,163,184,0.10)', 'icon' => '#94a3b8', 'ring' => 'rgba(148,163,184,0.20)'],
    'info'    => ['bg' => 'rgba(57,150,211,0.10)',  'icon' => '#3996d3', 'ring' => 'rgba(57,150,211,0.20)'],
    'success' => ['bg' => 'rgba(23,143,95,0.10)',   'icon' => '#178f5f', 'ring' => 'rgba(23,143,95,0.20)'],
    'warning' => ['bg' => 'rgba(234,179,8,0.12)',   'icon' => '#ca8a04', 'ring' => 'rgba(234,179,8,0.22)'],
    'danger'  => ['bg' => 'rgba(180,35,24,0.10)',   'icon' => '#b42318', 'ring' => 'rgba(180,35,24,0.20)'],
];

$colors = $toneColors[$tone] ?? $toneColors['neutral'];
$path   = $iconPaths[$icon] ?? $iconPaths['inbox'];
@endphp

<div {{ $attributes->merge(['class' => 'empty-state-block']) }}>
    <div class="empty-state-icon-wrap" style="background: {{ $colors['bg'] }}; box-shadow: 0 0 0 8px {{ $colors['ring'] }};">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none"
             stroke="{{ $colors['icon'] }}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            {!! $path !!}
        </svg>
    </div>
    <p class="empty-state-title">{{ $title }}</p>
    <p class="empty-state-message">{{ $message }}</p>
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
