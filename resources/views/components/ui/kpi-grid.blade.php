@props(['min' => '180px'])

<div {{ $attributes->merge(['class' => 'app-kpi-grid grid gap-3', 'style' => '--app-kpi-min: '.$min.';']) }}>
    {{ $slot }}
</div>
