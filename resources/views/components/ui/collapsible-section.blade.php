@props([
    'title',
    'open' => false,
])

<details {{ $attributes->merge(['class' => 'app-card app-collapsible overflow-hidden p-0']) }} @if($open) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-sm font-extrabold text-[#1c203d]">
        <span>{{ $title }}</span>
        <svg class="app-collapsible-chevron h-4 w-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </summary>

    <div class="border-t border-[#d8ecf8] px-5 py-4">
        {{ $slot }}
    </div>
</details>
