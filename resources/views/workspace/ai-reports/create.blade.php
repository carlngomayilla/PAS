@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Nouveau rapport IA</h1>
                <p class="text-sm text-slate-500">Generation basee sur un snapshot metier calcule par Laravel.</p>
            </div>
            <a class="btn btn-outline" href="{{ route('workspace.ai-reports.index') }}">Retour</a>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('workspace.ai-reports.generate') }}" class="rounded-lg border border-[#d8ecf8] bg-white p-4">
            @csrf
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="form-label" for="report_type">Type</label>
                    <select id="report_type" name="report_type" required class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="title">Titre</label>
                    <input id="title" name="title" value="{{ old('title') }}" class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                </div>
                <div>
                    <label class="form-label" for="period_start">Periode debut</label>
                    <input id="period_start" name="period_start" type="date" value="{{ old('period_start') }}" class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                </div>
                <div>
                    <label class="form-label" for="period_end">Periode fin</label>
                    <input id="period_end" name="period_end" type="date" value="{{ old('period_end') }}" class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                </div>
                <div>
                    <label class="form-label" for="direction_id">Direction</label>
                    <select id="direction_id" name="direction_id" class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                        <option value="">Toutes</option>
                        @foreach ($directions as $direction)
                            <option value="{{ $direction->id }}">{{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="service_id">Service</label>
                    <select id="service_id" name="service_id" class="w-full rounded-lg border border-[#d8ecf8] bg-white p-3 text-sm">
                        <option value="">Tous</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}">{{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-4 flex justify-end">
                <button class="btn btn-primary" type="submit">Generer</button>
            </div>
        </form>
    </section>
</div>
@endsection
