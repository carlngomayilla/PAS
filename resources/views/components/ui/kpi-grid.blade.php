@props(['min' => '180px'])

<div {{ $attributes->merge(['class' => 'grid gap-3'])->style(['grid-template-columns' => 'repeat(auto-fit, minmax('.$min.', 1fr))']) }}>
    {{ $slot }}
</div>
