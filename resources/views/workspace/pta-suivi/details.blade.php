@extends('layouts.workspace')

@section('title', 'Detail action PTA')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-xs font-black uppercase tracking-wide text-[#3996d3]">Controle PTA</p>
            <h1 class="text-2xl font-black text-[#17324a]">Detail de l'action PTA</h1>
        </div>
        <a class="btn btn-secondary rounded-xl px-4 py-2 text-sm" href="{{ route('pta.suivi.index') }}">Retour au Suivi PTA</a>
    </div>

    <section class="rounded-lg border border-slate-200 bg-slate-50 p-4 shadow-sm">
        @include('workspace.pta-suivi.partials.details')
    </section>
@endsection
