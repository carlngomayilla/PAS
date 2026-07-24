@props([
    'items' => [],
])

<div {{ $attributes->merge(['class' => 'space-y-2']) }}>
    @forelse ($items as $item)
        <div class="flex items-start justify-between gap-3 rounded-[var(--dashboard-card-radius)] border border-[var(--dashboard-border)] bg-[var(--dashboard-surface-muted)] p-3">
            <div>
                <p class="text-sm font-semibold text-[var(--dashboard-text)]">{{ $item['title'] ?? '-' }}</p>
                @if (! empty($item['message']))
                    <p class="mt-1 text-xs text-[var(--dashboard-muted)]">{{ $item['message'] }}</p>
                @endif
            </div>
            <x-dashboard.status-badge :status="$item['status'] ?? 'info'" :label="$item['label'] ?? ($item['status'] ?? 'info')" />
        </div>
    @empty
        <x-dashboard.empty-state title="Aucune alerte" message="Les points de controle prioritaires sont au calme." />
    @endforelse
</div>
