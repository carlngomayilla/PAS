@extends('layouts.workspace')

@section('content')
    <div class="app-screen-flow">
    <section class="showcase-hero mb-4 app-screen-block">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">Espace de travail</span>
                <h1 class="showcase-title">Profil utilisateur</h1>
            </div>
        </div>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <h2 class="showcase-panel-title">Informations du compte</h2>
        <div class="mt-3 flex flex-wrap items-start gap-3">
            @if ($user->profile_photo_url)
                <img src="{{ $user->profile_photo_url }}" alt="Photo de profil de {{ $user->name }}" class="h-16 w-16 rounded-2xl object-cover ring-2 ring-white shadow-sm">
            @else
                <span class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-[#3996d3] text-xl font-semibold text-white">
                    {{ $user->profile_initials }}
                </span>
            @endif
            <div>
                <p class="font-semibold text-[#17324a]">{{ $user->name }}</p>
                <p class="text-sm text-[#667085]">{{ $user->email }}</p>
                <p class="text-sm text-[#667085]">
                    Profil: <strong>{{ $profil['role_label'] }}</strong> ({{ $profil['role'] }})
                </p>
                <p class="text-sm text-[#667085]">
                    Portee: <strong>{{ $profil['scope'] }}</strong>
                </p>
                <p class="text-sm text-[#667085]">
                    Direction: <strong>{{ $user->direction?->libelle ?? 'Aucune' }}</strong>
                    | Service: <strong>{{ $user->service?->libelle ?? 'Aucun' }}</strong>
                </p>
            </div>
        </div>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <h2 class="showcase-panel-title">Interactions métier</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($profil['items'] as $item)
                <article class="rounded-2xl border border-slate-200/85 bg-white/95 p-4">
                    <strong class="text-[#17324a]">{{ $item['module'] }}</strong>
                    <p class="text-[#667085] mt-2">
                        Operations: {{ implode(' | ', $item['operations']) }}
                    </p>
                    <p class="text-[#667085] mt-1">
                        Portee: {{ $item['portee'] }}
                    </p>
                </article>
            @empty
                <p class="text-[#667085]">Aucune interaction configuree.</p>
            @endforelse
        </div>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <h2 class="showcase-panel-title">Modules web disponibles</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($modules as $module)
                <article class="rounded-2xl border border-slate-200/85 bg-white/95 p-4">
                    <strong class="text-[#17324a]">{{ $module['label'] }}</strong>
                    <p class="text-[#667085] mt-2">{{ $module['description'] }}</p>
                    <p class="mt-2">
                        <span class="anbg-badge {{ $module['can_write'] ? 'anbg-badge-success' : 'anbg-badge-neutral' }} px-2 py-0.5 text-xs">{{ $module['can_write'] ? 'Ecriture autorisee' : 'Lecture seule' }}</span>
                    </p>
                    <p class="text-[#667085] mt-2"><code class="text-xs">{{ $module['endpoint'] }}</code></p>
                    <p class="mt-2.5">
                        <a class="btn btn-primary" href="{{ $module['web_route'] }}">Ouvrir</a>
                    </p>
                </article>
            @endforeach
        </div>
    </section>
    </div>
@endsection
