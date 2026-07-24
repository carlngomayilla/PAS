@extends('layouts.workspace')

@section('content')
<div class="app-screen-flow">
    <section class="showcase-panel app-screen-block" data-keep-empty="1" data-keep-accordion="0">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="showcase-panel-title">Consommation IA</h1>
                <p class="text-sm text-slate-500">Appels, tokens, cout estime et budget mensuel.</p>
            </div>
            <a class="btn btn-outline" href="{{ route('workspace.ai-imports.index') }}">Imports IA</a>
        </div>

        <div class="mb-5 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Budget mensuel</p>
                <p class="mt-2 text-3xl font-extrabold text-[#1c203d]">{{ number_format($monthlyBudget, 2) }} USD</p>
            </div>
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Consomme</p>
                <p class="mt-2 text-3xl font-extrabold text-[#3996d3]">{{ number_format($monthlyTotal, 4) }} USD</p>
            </div>
            <div class="rounded-lg border border-[#fde7c3] bg-[#fffaf2] p-4">
                <p class="text-xs font-bold uppercase text-slate-500">Reste</p>
                <p class="mt-2 text-3xl font-extrabold text-[#c77700]">{{ number_format(max(0, $monthlyBudget - $monthlyTotal), 4) }} USD</p>
            </div>
        </div>

        <div class="mb-6 overflow-x-auto rounded-lg border border-[#d8ecf8] bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Module</th>
                        <th class="px-3 py-2">Appels</th>
                        <th class="px-3 py-2">Tokens</th>
                        <th class="px-3 py-2">Cout</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($byModule as $module)
                        <tr>
                            <td class="px-3 py-2 font-semibold">{{ $module->module }}</td>
                            <td class="px-3 py-2">{{ $module->calls }}</td>
                            <td class="px-3 py-2">{{ $module->tokens }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $module->cost, 6) }} USD</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-6 text-center text-slate-500" colspan="4">Aucune consommation enregistree.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Utilisateur</th>
                        <th class="px-3 py-2">Operation</th>
                        <th class="px-3 py-2">Modele</th>
                        <th class="px-3 py-2">Tokens</th>
                        <th class="px-3 py-2">Cout</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="px-3 py-2">{{ $log->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2">{{ $log->user?->name ?: '-' }}</td>
                            <td class="px-3 py-2">{{ $log->module }} / {{ $log->operation_type }}</td>
                            <td class="px-3 py-2">{{ $log->model ?: '-' }}</td>
                            <td class="px-3 py-2">{{ $log->total_tokens }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $log->total_cost_usd, 6) }} USD</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-slate-500" colspan="6">Aucun journal IA.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $logs->links() }}</div>
    </section>
</div>
@endsection
