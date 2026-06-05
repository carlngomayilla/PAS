@extends('layouts.workspace')

@section('title', 'Notifications')

@php
    $activeTab = $activeTab ?? 'notifications';
    $canReadAlerts = (bool) ($canReadAlerts ?? false);
    $alertSummary = is_array($alertSummary ?? null) ? $alertSummary : [];
    $alertItems = $alertItems ?? collect();
    $alertUnreadCount = (int) ($alertSummary['unread'] ?? 0);
    $alertTotalCount = (int) ($alertSummary['total'] ?? 0);
    $tabClass = static fn (string $tab): string => $activeTab === $tab
        ? 'border-[#3996d3] bg-[#3996d3] text-white'
        : 'border-[#3996d3]/25 bg-white text-[#17324a] hover:bg-[#eef6fc]';
    $levelStyles = [
        'urgence' => ['card' => 'border-red-500/45 bg-red-50/95', 'badge' => 'anbg-badge anbg-badge-danger', 'dot' => 'bg-red-500'],
        'critical' => ['card' => 'border-[#f9b13c]/45 bg-[#fff0df]/95', 'badge' => 'anbg-badge anbg-badge-danger', 'dot' => 'bg-[#f9b13c]'],
        'warning' => ['card' => 'border-[#f9b13c]/40 bg-[#fff8d6]/95', 'badge' => 'anbg-badge anbg-badge-warning', 'dot' => 'bg-[#f0e509]'],
        'info' => ['card' => 'border-[#3996d3]/40 bg-[#eef6fc]/95', 'badge' => 'anbg-badge anbg-badge-info', 'dot' => 'bg-[#3996d3]'],
    ];
@endphp

@section('content')
    <div class="app-screen-flow">
        <section class="showcase-hero mb-4 app-screen-block">
            <div class="showcase-hero-body">
                <div>
                    <span class="showcase-eyebrow">Centre personnel</span>
                    <h1 class="showcase-title">Notifications</h1>
                </div>

                <div class="showcase-action-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3996d3]"></span>
                        {{ $unreadCount }} notification(s) non lue(s)
                    </span>

                    @if ($canReadAlerts)
                        <span class="showcase-chip">
                            <span class="showcase-chip-dot bg-[#f9b13c]"></span>
                            {{ $alertUnreadCount }} alerte(s) non lue(s)
                        </span>
                    @endif

                    @if ($activeTab === 'notifications' && $unreadCount > 0)
                        <form method="POST" action="{{ route('workspace.notifications.read_all') }}">
                            @csrf
                            <button class="btn btn-primary rounded-2xl px-4 py-2.5" type="submit">
                                Tout marquer comme lu
                            </button>
                        </form>
                    @elseif ($activeTab === 'alertes' && $canReadAlerts && $alertUnreadCount > 0)
                        <form method="POST" action="{{ route('workspace.alertes.read_all', ['limit' => 100]) }}">
                            @csrf
                            <button class="btn btn-primary rounded-2xl px-4 py-2.5" type="submit">
                                Tout marquer comme lu
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        <section class="showcase-toolbar app-screen-block">
            <div class="flex flex-wrap items-center gap-2">
                <a class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold transition {{ $tabClass('notifications') }}" href="{{ route('workspace.notifications.index') }}">
                    Notifications
                    @if ($unreadCount > 0)
                        <span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-[10px]">{{ $unreadCount }}</span>
                    @endif
                </a>
                @if ($canReadAlerts)
                    <a class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold transition {{ $tabClass('alertes') }}" href="{{ route('workspace.notifications.index', ['tab' => 'alertes']) }}">
                        Alertes
                        @if ($alertUnreadCount > 0)
                            <span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-[10px]">{{ $alertUnreadCount }}</span>
                        @endif
                    </a>
                @endif
            </div>
        </section>

        @if ($activeTab === 'alertes' && $canReadAlerts)
            <section class="showcase-summary-grid app-screen-kpis">
                @foreach ([
                    ['label' => 'Total', 'value' => $alertTotalCount, 'class' => 'text-[#3996d3]'],
                    ['label' => 'Non lues', 'value' => $alertUnreadCount, 'class' => 'text-[#f9b13c]'],
                    ['label' => 'Urgences', 'value' => (int) ($alertSummary['urgence'] ?? 0), 'class' => 'text-red-600'],
                    ['label' => 'Critiques', 'value' => (int) ($alertSummary['critical'] ?? 0), 'class' => 'text-[#f9b13c]'],
                ] as $card)
                    <article class="showcase-kpi-card">
                        <p class="showcase-kpi-label">{{ $card['label'] }}</p>
                        <p class="showcase-kpi-number {{ $card['class'] }}">{{ $card['value'] }}</p>
                    </article>
                @endforeach
            </section>
        @endif

        <section class="showcase-panel app-screen-block">
            @if ($activeTab === 'alertes' && $canReadAlerts)
                <div class="space-y-3">
                    @forelse ($alertItems as $alert)
                        @php
                            $level = (string) ($alert['niveau'] ?? 'info');
                            $style = $levelStyles[$level] ?? $levelStyles['info'];
                            $isUnread = (bool) ($alert['is_unread'] ?? false);
                        @endphp
                        <a
                            class="block rounded-2xl border p-4 shadow-sm transition hover:border-[#3996d3]/55 hover:bg-white {{ $isUnread ? $style['card'] : 'border-slate-200 bg-white/95' }}"
                            href="{{ $alert['read_url'] ?? ($alert['target_url'] ?? route('workspace.notifications.index', ['tab' => 'alertes'])) }}"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($isUnread)
                                            <span class="inline-flex h-2.5 w-2.5 rounded-full {{ $style['dot'] }}"></span>
                                        @endif
                                        <strong class="text-[#1c203d]">{{ $alert['titre'] ?? 'Alerte' }}</strong>
                                        <span class="{{ $style['badge'] }} px-2 py-0.5 text-xs">
                                            {{ $alert['niveau_label'] ?? $level }}
                                        </span>
                                        <span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-xs">
                                            {{ $alert['type_label'] ?? 'Alerte' }}
                                        </span>
                                        @if ($isUnread)
                                            <span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-xs">Non lue</span>
                                        @endif
                                    </div>

                                    <p class="mt-2 text-sm leading-6 text-[#667085]">{{ $alert['message'] ?? '' }}</p>

                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[#667085]">
                                        <span>{{ $alert['direction'] ?? '-' }}</span>
                                        <span>{{ $alert['service'] ?? '-' }}</span>
                                        <span>{{ $alert['date_label'] ?? '' }}</span>
                                    </div>
                                </div>

                                <span class="btn btn-secondary rounded-2xl px-4 py-2.5">Ouvrir</span>
                            </div>
                        </a>
                    @empty
                        <x-ui.empty-state
                            title="Aucune alerte"
                            message="Les alertes liees a votre perimetre apparaitront ici."
                            icon="bell"
                            tone="info"
                        />
                    @endforelse
                </div>
            @else
                <div class="space-y-3">
                    @forelse ($notifications as $notification)
                        @php
                            $data = is_array($notification->data ?? null) ? $notification->data : [];
                            $title = (string) ($data['title'] ?? $data['titre'] ?? 'Notification');
                            $message = (string) ($data['message'] ?? $data['body'] ?? '');
                            $level = strtolower((string) ($data['level'] ?? $data['niveau'] ?? 'info'));
                            $badgeClass = match ($level) {
                                'critical', 'critique', 'urgence' => 'anbg-badge anbg-badge-danger',
                                'warning', 'avertissement' => 'anbg-badge anbg-badge-warning',
                                default => 'anbg-badge anbg-badge-info',
                            };
                        @endphp

                        <article class="rounded-2xl border border-[#3996d3]/20 bg-[#eef6fc]/70 p-4 shadow-sm transition hover:border-[#3996d3]/45 hover:bg-white/95">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <strong class="text-[#1c203d]">{{ $title }}</strong>
                                        <span class="{{ $badgeClass }} px-2 py-0.5 text-xs">
                                            {{ $level }}
                                        </span>
                                        @if ($notification->read_at === null)
                                            <span class="anbg-badge anbg-badge-success px-2 py-0.5 text-xs">Non lue</span>
                                        @endif
                                    </div>

                                    @if ($message !== '')
                                        <p class="mt-2 text-sm text-[#667085]">{{ $message }}</p>
                                    @endif

                                    <p class="mt-2 text-xs text-[#667085]">
                                        {{ optional($notification->created_at)->format('d/m/Y H:i') }}
                                    </p>
                                </div>

                                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.notifications.read', $notification->id) }}">
                                    Ouvrir
                                </a>
                            </div>
                        </article>
                    @empty
                        <x-ui.empty-state
                            title="Aucune notification"
                            message="Les notifications liees a votre perimetre apparaitront ici."
                            icon="bell"
                            tone="info"
                        />
                    @endforelse
                </div>

                <div class="mt-4">
                    {{ $notifications->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
