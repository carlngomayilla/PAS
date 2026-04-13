@props([
    'title' => 'Aucune donnee',
    'message' => 'Aucun element ne correspond au perimetre courant.',
])

<div {{ $attributes->merge(['class' => 'rounded-[1.15rem] border border-dashed border-slate-300/80 bg-slate-50/70 px-4 py-8 text-center dark:border-slate-700 dark:bg-slate-900/60']) }}>
    <p class="font-semibold text-slate-900 dark:text-slate-100">{{ $title }}</p>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $message }}</p>
</div>
