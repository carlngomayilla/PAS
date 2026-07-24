<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,360px)]">
    <x-dashboard.section-card title="Priorites" subtitle="Cartes limitees au profil courant">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @forelse (($essentialDashboard['cards'] ?? []) as $card)
                <x-dashboard.kpi-card
                    :label="$card['label'] ?? '-'"
                    :value="$card['value'] ?? '-'"
                    :caption="$card['caption'] ?? null"
                    :tone="$card['tone'] ?? 'info'"
                    :href="$card['href'] ?? null"
                />
            @empty
                <x-dashboard.empty-state title="Aucune carte" message="Votre profil n'a pas encore de carte prioritaire." />
            @endforelse
        </div>
    </x-dashboard.section-card>

    <x-dashboard.section-card title="Alertes" subtitle="Points actifs a traiter">
        <x-dashboard.alert-strip :items="$essentialDashboard['alerts'] ?? []" />
    </x-dashboard.section-card>
</div>
