@php
    $essentialDashboard = is_array($essentialDashboard ?? null) ? $essentialDashboard : [
        'profile' => 'default',
        'label' => 'Vue essentielle',
        'cards' => [],
        'alerts' => [],
    ];
    $profilePartial = 'dashboard.partials.role-'.$essentialDashboard['profile'];
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-[var(--dashboard-muted)]">{{ $essentialDashboard['label'] }}</p>
            <h1 class="text-2xl font-semibold text-[var(--dashboard-text)]">Tableau de bord</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-dashboard.drilldown-button :href="route('workspace.pilotage')" label="Pilotage PAS/PAO/PTA" />
            <x-dashboard.drilldown-button :href="route('dashboard', ['dashboardTab' => 'advanced'])" label="Vue analytique" />
        </div>
    </div>

    @if (view()->exists($profilePartial))
        @include($profilePartial, ['essentialDashboard' => $essentialDashboard])
    @else
        @include('dashboard.partials.role-default', ['essentialDashboard' => $essentialDashboard])
    @endif

    @if (in_array((string) request('dashboardTab'), ['advanced', 'charts', 'tables'], true))
        <div class="pt-2">
            @include('partials.dashboard-analytics')
        </div>
    @endif
</div>
