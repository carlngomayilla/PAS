@extends('layouts.admin')

@section('title', 'Unités DG')

@section('content')
    <div class="app-screen-flow">
        <div class="showcase-toolbar mb-4">
            <div class="showcase-toolbar-copy">
                <h1 class="showcase-panel-title">Unités de la Direction Générale</h1>
                <p class="showcase-toolbar-subtitle">SCIQ · DGA · Cabinet · UCAS — chefs d’unité et membres rattachés.</p>
            </div>
            <div class="showcase-toolbar-actions">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
            </div>
        </div>

        @if (session('success'))
            <div class="anbg-toast-inline anbg-badge-success mb-4">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="anbg-toast-inline anbg-badge-danger mb-4">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-2">
            @forelse ($unites as $unite)
                @php
                    $chef = $unite->chef;
                    $members = $unite->users->sortBy('name')->values();
                    $candidates = $candidatesByUnite->get($unite->id, collect());
                    $isGlobal = (bool) $unite->portee_globale;
                @endphp
                <article class="showcase-panel">
                    <header class="mb-3 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-[#3996d3]">{{ $unite->code }}</p>
                            <h2 class="showcase-panel-title">{{ $unite->libelle }}</h2>
                            <p class="text-xs text-slate-500">
                                Rattachée à {{ $unite->direction?->code ?? '—' }} ·
                                {{ $isGlobal ? 'Portée globale (vue agence)' : 'Portée limitée à l’unité' }}
                            </p>
                        </div>
                        <span class="anbg-badge {{ $isGlobal ? 'anbg-badge-info' : 'anbg-badge-warning' }}">
                            {{ $isGlobal ? 'Globale' : 'Limitée' }}
                        </span>
                    </header>

                    <div class="mb-4 rounded-2xl border border-[#d8ecf8] bg-[#f7fbfd] p-3">
                        <p class="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">Chef d’unité</p>
                        <form method="POST" action="{{ route('workspace.super-admin.unites-dg.set-chef', $unite) }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            @method('PUT')
                            <div class="flex-1 min-w-[220px]">
                                <select name="chef_user_id" class="w-full rounded-xl border border-[#cbd5e1] bg-white px-3 py-2 text-sm">
                                    <option value="">— Aucun chef désigné —</option>
                                    @foreach ($candidates as $candidate)
                                        <option value="{{ $candidate->id }}" @selected((int) $unite->chef_user_id === (int) $candidate->id)>
                                            {{ $candidate->name }} ({{ $candidate->role }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm rounded-xl">Enregistrer</button>
                        </form>
                        @if ($chef)
                            <p class="mt-2 text-xs text-slate-500">
                                Actuel : <strong class="text-slate-700">{{ $chef->name }}</strong> · {{ $chef->email }}
                            </p>
                        @else
                            <p class="mt-2 text-xs text-amber-700">Aucun chef d’unité désigné pour le moment.</p>
                        @endif
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">
                            Membres rattachés ({{ $members->count() }})
                        </p>
                        @if ($members->isEmpty())
                            <p class="text-sm text-slate-500">Aucun utilisateur rattaché à cette unité.</p>
                        @else
                            <ul class="space-y-1.5">
                                @foreach ($members as $member)
                                    <li class="flex items-center justify-between gap-3 rounded-xl border border-[#e2e8f0] bg-white px-3 py-2 text-sm">
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold text-slate-700">{{ $member->name }}</p>
                                            <p class="truncate text-xs text-slate-500">{{ $member->email }}</p>
                                        </div>
                                        <span class="anbg-badge anbg-badge-neutral shrink-0">{{ $member->role }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </article>
            @empty
                <div class="showcase-panel">
                    <p class="text-sm text-slate-500">Aucune unité DG configurée. Vérifie le seeder <code>UniteDgSeeder</code>.</p>
                </div>
            @endforelse
        </div>
    </div>
@endsection
