@props([
    'title' => null,
    'description' => null,
])

<section {{ $attributes->merge(['class' => 'form-section app-card p-5']) }}>
    @if ($title)
        <div class="mb-4">
            <h3 class="text-base font-extrabold text-[#1c203d]">{{ $title }}</h3>
            @if ($description)
                <p class="mt-1 text-sm text-[#667085]">{{ $description }}</p>
            @endif
        </div>
    @endif

    <div class="app-form">
        {{ $slot }}
    </div>
</section>
