@props([
    'title' => null,
])

<div {{ $attributes->merge(['class' => 'mb-4 mt-2 text-center']) }}>
    <h2 class="text-xl font-extrabold tracking-tight text-[#1c203d]">{{ $title ?? $slot }}</h2>
</div>
