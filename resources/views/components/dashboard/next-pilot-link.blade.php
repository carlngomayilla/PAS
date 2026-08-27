@props(['filters' => []])

@if ((bool) config('dashboard.next_pilot.enabled', false))
    @php
        $configuredUrl = config('dashboard.next_pilot.url', '/dashboard-pilot');
        $nextPilotBaseUrl = is_string($configuredUrl)
            && str_starts_with($configuredUrl, '/')
            && ! str_starts_with($configuredUrl, '//')
            && ! str_contains($configuredUrl, chr(92))
            && parse_url($configuredUrl, PHP_URL_PATH) === $configuredUrl
                ? $configuredUrl
                : '/dashboard-pilot';
        $nextPilotFilters = collect(is_array($filters) ? $filters : [])
            ->only([
                'exercice',
                'periode',
                'direction_id',
                'service_id',
                'responsable_id',
                'statut_action',
                'statut_suivi',
                'statut_delai',
                'alerte_echeance',
            ])
            ->filter(static fn (mixed $value): bool => is_scalar($value) && ! is_bool($value))
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '' && strtolower($value) !== 'all')
            ->all();
        $nextPilotHref = $nextPilotBaseUrl.($nextPilotFilters === []
            ? ''
            : '?'.http_build_query($nextPilotFilters, '', '&', PHP_QUERY_RFC3986));
    @endphp

    <a
        href="{{ $nextPilotHref }}"
        {{ $attributes->merge(['class' => '-mt-2 mb-4 ml-auto flex min-h-11 w-fit items-center gap-2 rounded-xl border border-[#3996d3]/35 bg-[#f4f9fd] px-3 py-2 text-sm font-bold text-[#176a9d] shadow-sm transition hover:border-[#3996d3]/60 hover:bg-[#e8f3fb] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#3996d3] focus-visible:ring-offset-2 dark:border-sky-400/40 dark:bg-slate-900 dark:text-sky-200 dark:hover:bg-slate-800 dark:focus-visible:ring-offset-slate-950']) }}
        aria-label="Ouvrir le nouveau Pilotage, version pilote"
        data-next-dashboard-pilot-link
    >
        <svg class="h-4 w-4" aria-hidden="true" focusable="false" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h6m0 0v6m0-6L10 16l-3-3-4 4" />
        </svg>
        <span>Nouveau Pilotage</span>
        <span class="rounded-full bg-[#176a9d] px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-white dark:bg-sky-300 dark:text-slate-950">Pilote</span>
    </a>
@endif
