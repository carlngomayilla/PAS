@extends('layouts.workspace')

@section('title', 'Notifications')

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
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        {{ $unreadCount }} non lue(s)
                    </span>

                    @if ($unreadCount > 0)
                        <form method="POST" action="{{ route('workspace.notifications.read_all') }}">
                            @csrf
                            <button class="btn btn-primary rounded-2xl px-4 py-2.5" type="submit">
                                Tout marquer comme lu
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        <section class="showcase-panel app-screen-block">
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

                    <article class="rounded-2xl border border-slate-200/85 bg-white/95 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="text-[#17324a]">{{ $title }}</strong>
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
        </section>
    </div>
@endsection
