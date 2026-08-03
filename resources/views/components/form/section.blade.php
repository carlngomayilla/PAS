@props([
    'title' => null,
    'description' => null,
])

<section {{ $attributes->merge(['class' => 'form-section app-form-section app-card']) }}>
    @if ($title)
        <div class="form-section-header app-form-section-header">
            <h3 class="form-section-title">{{ $title }}</h3>
            @if ($description)
                <p class="form-section-subtitle">{{ $description }}</p>
            @endif
        </div>
    @endif

    <div class="app-form">
        {{ $slot }}
    </div>
</section>
