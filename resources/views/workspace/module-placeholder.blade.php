@extends('layouts.workspace')

@section('title', $module['label'] ?? 'Module')

@section('content')
    <div class="app-screen-flow">
        <section class="showcase-hero mb-4 app-screen-block">
            <div class="showcase-hero-body">
                <div>
                    <span class="showcase-eyebrow">Module autorise</span>
                    <h1 class="showcase-title">{{ $module['label'] ?? 'Module' }}</h1>
                </div>
            </div>
        </section>

        <section class="showcase-panel app-screen-block">
            <x-ui.empty-state
                title="Ecran en cours de raccordement"
                message="Ce module est bien autorise pour votre profil. Les routes et donnees metier seront raccordees progressivement selon le lot fonctionnel correspondant."
                icon="settings"
                tone="info"
            />

            <div class="mt-4 rounded-2xl border border-slate-200/85 bg-white/95 p-4 text-sm text-[#667085]">
                <p><strong class="text-[#17324a]">Perimetre :</strong> {{ $user->direction?->libelle ?? 'Global' }} / {{ $user->service?->libelle ?? 'Tous services' }}</p>
                <p class="mt-1"><strong class="text-[#17324a]">Endpoint cible :</strong> <code>{{ $module['endpoint'] ?? '' }}</code></p>
            </div>
        </section>
    </div>
@endsection
