@extends('layouts.workspace')

@section('content')
    <section class="ui-card mb-3.5">
        <h1>Espace de travail par profil</h1>
        <div class="mt-3 flex flex-wrap items-start gap-3">
            @if ($user->profile_photo_url)
                <img src="{{ $user->profile_photo_url }}" alt="Photo de profil de {{ $user->name }}" class="h-16 w-16 rounded-full object-cover ring-2 ring-white shadow-sm">
            @else
                <span class="inline-flex h-16 w-16 items-center justify-center rounded-full bg-slate-800 text-xl font-semibold text-white">
                    {{ $user->profile_initials }}
                </span>
            @endif
            <div>
                <p class="font-semibold text-slate-900">{{ $user->name }}</p>
                <p class="text-sm text-slate-600">{{ $user->email }}</p>
                <p class="text-sm text-slate-600">
                    Profil: <strong>{{ $profil['role_label'] }}</strong> ({{ $profil['role'] }})
                </p>
                <p class="text-sm text-slate-600">
                    Portee: <strong>{{ $profil['scope'] }}</strong>
                </p>
                <p class="text-sm text-slate-600">
                    Direction: <strong>{{ $user->direction?->libelle ?? 'Aucune' }}</strong>
                    | Service: <strong>{{ $user->service?->libelle ?? 'Aucun' }}</strong>
                </p>
            </div>
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Interactions metier</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            @forelse ($profil['items'] as $item)
                <article class="ui-card mb-3.5 !mb-0">
                    <strong>{{ $item['module'] }}</strong>
                    <p class="text-slate-600 mt-2">
                        Operations: {{ implode(' | ', $item['operations']) }}
                    </p>
                    <p class="text-slate-600 mt-1">
                        Portee: {{ $item['portee'] }}
                    </p>
                </article>
            @empty
                <p class="text-slate-600">Aucune interaction configuree.</p>
            @endforelse
        </div>
    </section>

    <section class="ui-card mb-3.5">
        <h2>Modules web disponibles</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            @foreach ($modules as $module)
                <article class="ui-card mb-3.5 !mb-0">
                    <strong>{{ $module['label'] }}</strong>
                    <p class="text-slate-600 mt-2">{{ $module['description'] }}</p>
                    <p class="mt-2">
                        <span class="inline-block rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">{{ $module['can_write'] ? 'Ecriture autorisee' : 'Lecture seule' }}</span>
                    </p>
                    <p class="text-slate-600 mt-2"><code>{{ $module['endpoint'] }}</code></p>
                    <p class="mt-2.5">
                        <a class="btn btn-primary" href="{{ $module['web_route'] }}">Ouvrir</a>
                    </p>
                </article>
            @endforeach
        </div>
    </section>
@endsection
