@extends('layouts.admin')

@section('title', $title ?? 'Module')

@section('content')
    <section class="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <h2 class="text-lg font-semibold">{{ $title ?? 'Module' }}</h2>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
            Cette page est prete pour brancher votre controller metier.
        </p>
        <div class="mt-4 flex flex-wrap gap-2">
            <a href="{{ route('admin.dashboard') }}" class="rounded-xl bg-[#3996d3] px-3 py-2 text-sm font-medium text-white hover:bg-[#3996d3]">
                Retour dashboard
            </a>
            <a href="{{ route('workspace.actions.index') }}" class="rounded-xl bg-slate-100 px-3 py-2 text-sm font-medium text-slate-800 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700">
                Aller vers execution
            </a>
        </div>
    </section>
@endsection
