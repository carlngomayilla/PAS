@extends('layouts.workspace')

@section('title', 'Documentation API')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/swagger-ui.css') }}">
@endpush

@section('content')
    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">Gouvernance technique</span>
                <h1 class="showcase-title">Documentation API</h1>
                <p class="showcase-subtitle">
                    Contrat OpenAPI local charge depuis <code>{{ $specUrl }}</code> avec interface Swagger disponible meme hors connexion.
                </p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        OpenAPI local
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3996d3]"></span>
                        Swagger hors ligne
                    </span>
                </div>
                <div class="showcase-action-row mt-4">
                    <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ $specUrl }}" target="_blank" rel="noreferrer">Ouvrir le YAML</a>
                </div>
            </div>
        </div>
    </section>

    <section class="showcase-panel p-0 overflow-hidden">
        <div id="swagger-ui" data-spec-url="{{ $specUrl }}" class="min-h-[70vh]"></div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/swagger-ui/swagger-ui-bundle.js') }}"></script>
    <script>
        (function () {
            var container = document.getElementById('swagger-ui');
            if (!container || typeof window.SwaggerUIBundle !== 'function') {
                return;
            }

            var specUrl = container.dataset.specUrl;
            if (!specUrl) {
                return;
            }

            window.SwaggerUIBundle({
                url: specUrl,
                dom_id: '#swagger-ui',
                deepLinking: true,
                docExpansion: 'list',
                displayRequestDuration: true,
                persistAuthorization: true,
            });
        })();
    </script>
@endpush
