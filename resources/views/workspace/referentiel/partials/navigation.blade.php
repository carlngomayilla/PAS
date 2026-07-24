@php
    $tabs = [
        ['code' => 'directions', 'label' => 'Directions', 'route' => 'workspace.referentiel.directions.index'],
        ['code' => 'services', 'label' => 'Services', 'route' => 'workspace.referentiel.services.index'],
        ['code' => 'utilisateurs', 'label' => 'Utilisateurs', 'route' => 'workspace.referentiel.utilisateurs.index'],
    ];
@endphp

<nav class="flex gap-1 overflow-x-auto border-b border-slate-200 pb-px dark:border-slate-700" aria-label="Sections du référentiel organisationnel">
    @foreach ($tabs as $tab)
        <a
            href="{{ route($tab['route']) }}"
            @if (($active ?? '') === $tab['code']) aria-current="page" @endif
            class="inline-flex min-h-10 shrink-0 items-center border-b-2 px-4 py-2 text-sm font-semibold transition {{ ($active ?? '') === $tab['code'] ? 'border-[#3996D3] text-[#176A9D] dark:text-sky-200' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100' }}"
        >
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
