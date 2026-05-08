@php
    $assetProfile = $profile ?? 'app';
    $fontHref = $assetProfile === 'guest'
        ? 'https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Manrope:wght@400;500;600;700;800&display=swap'
        : 'https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800&family=Source+Serif+4:wght@600;700&family=Manrope:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap';

    $viteEntries = $assetProfile === 'guest'
        ? ['resources/css/guest.css', 'resources/js/guest.js']
        : ['resources/css/app.css', 'resources/js/app.js'];
@endphp

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="{{ $fontHref }}" rel="stylesheet">
@vite($viteEntries)

<style>
    :root {
        {{ $appearanceSettings->cssVariablesInline() }};
    }
</style>
