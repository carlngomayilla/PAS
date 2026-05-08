@extends('layouts.guest')

@push('head')
    <style>
        .guest-footer { display: none !important; }
        body { background: #1c203d !important; padding: 0 !important; margin: 0 !important; }
    </style>
@endpush

@section('content')
@php
    $decode = static fn (string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $identifierLabel       = $platformSettings->get('login_identifier_label', 'Email ou matricule');
    $identifierPlaceholder = $platformSettings->get('login_identifier_placeholder', 'Email ou matricule');
    $formTitle             = $decode($platformSettings->get('login_form_title', 'Se connecter'));
    $footerText            = $platformSettings->get('footer_text', 'ANBG · Agence Nationale des Bourses du Gabon');
@endphp

<div class="login-root">

    {{-- ══════════════════════════════════════════════════
         PANNEAU GAUCHE — Identité bleue + sphères
    ══════════════════════════════════════════════════ --}}
    <aside class="login-left">

        {{-- Sphères décoratives 3D --}}
        <div class="login-brand">
            <x-brand.logo variant="full" class="login-brand-logo" />

        </div>
    </aside>

    {{-- ══════════════════════════════════════════════════
         PANNEAU DROIT — Formulaire blanc
    ══════════════════════════════════════════════════ --}}
    <main class="login-right" aria-label="Connexion">
        <div class="login-panel">

            {{-- Logo mobile uniquement --}}
            <div class="login-mobile-logo">
                <x-brand.logo variant="full" class="login-mobile-logo-img" />
            </div>

            {{-- En-tête --}}
            <div class="login-heading">
                <h1 class="login-title">{{ $formTitle }}</h1>
            </div>

            {{-- Erreur --}}
            @if ($errors->any())
                <div class="login-alert" role="alert">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            {{-- Formulaire --}}
            <form method="POST" action="{{ route('login') }}" class="login-form">
                @csrf

                {{-- Identifiant --}}
                <div class="login-field">
                    <svg class="login-field-ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>
                    </svg>
                    <input
                        id="loginId"
                        type="text"
                        name="email"
                        value="{{ old('email') }}"
                        placeholder="{{ $identifierPlaceholder }}"
                        autocomplete="username"
                        class="login-input"
                        required
                        autofocus
                    >
                </div>

                {{-- Mot de passe --}}
                <div class="login-field">
                    <svg class="login-field-ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
                    </svg>
                    <input
                        id="loginPwd"
                        type="password"
                        name="password"
                        placeholder="Mot de passe"
                        autocomplete="current-password"
                        class="login-input"
                        required
                    >
                </div>

                {{-- Options --}}
                <div class="login-opts">
                    <label for="remember" class="login-remember">
                        <input id="remember" type="checkbox" name="remember" value="1" class="login-check">
                        <span>Se souvenir de moi</span>
                    </label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="login-forgot">Mot de passe oublié ?</a>
                    @endif
                </div>

                {{-- Bouton principal --}}
                <button type="submit" class="login-submit">
                    Se connecter
                </button>

                {{-- Séparateur --}}
                <div class="login-divider">
                    <span>Accès sécurisé</span>
                </div>

                {{-- Note bas --}}
                <p class="login-note">{{ $footerText }}</p>
            </form>
        </div>
    </main>
</div>
@endsection
