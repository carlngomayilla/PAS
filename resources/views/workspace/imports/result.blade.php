@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block">
        <h1 class="showcase-panel-title">Resultat de l'import</h1>
        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <x-ui.stat-card title="Lignes valides" :value="$import->valid_rows" />
            <x-ui.stat-card title="Crees" :value="$import->created_count" />
            <x-ui.stat-card title="Mis a jour" :value="$import->updated_count" />
            <x-ui.stat-card title="Ignores" :value="$import->skipped_count" />
        </div>
        <div class="form-actions mt-4">
            <a class="btn btn-primary" href="{{ route('workspace.imports.index') }}">Retour a l'historique</a>
            <a class="btn btn-secondary" href="{{ route('workspace.pta.index') }}">Voir les PTA</a>
        </div>
    </section>
</div>
@endsection
