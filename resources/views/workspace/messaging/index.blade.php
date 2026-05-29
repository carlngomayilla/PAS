@extends('layouts.workspace')

@php
    use App\Models\ConversationParticipant;
    use App\Models\User;

    $roleLabel = static function (string $role): string {
        return match ($role) {
            User::ROLE_ADMIN => 'Administrateur',
            User::ROLE_DG => 'DG',
            User::ROLE_CABINET => 'CABINET',
            User::ROLE_PLANIFICATION => 'PLANIFICATION',
            User::ROLE_DIRECTION => 'DIRECTION',
            User::ROLE_SERVICE => 'SERVICES',
            User::ROLE_AGENT => 'AGENT',
            default => strtoupper($role),
        };
    };
@endphp

@section('content')
    @php
        $selectedTreeUserId = (int) (($contactCard['user'] ?? null)?->id ?? 0);
        $activeConversationId = (int) ($activeConversation?->id ?? 0);
    @endphp
    <div class="app-screen-flow">
    <section class="showcase-hero mb-4 app-screen-block">
        <div class="showcase-hero-body">
            <div class="max-w-3xl">
                <span class="showcase-eyebrow">Messagerie</span>
                <h1 class="showcase-title">Messagerie interne</h1>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3996d3]"></span>
                        {{ $conversations->count() }} conversation(s)
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#8fc043]"></span>
                        {{ $directoryUsers->count() }} collaborateur(s) visibles
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#f9b13c]"></span>
                        Organigramme ANBG interactif
                    </span>
                </div>
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('dashboard', ['dashboardTab' => 'analytics']) }}">
                    Retour dashboard
                </a>
            </div>
        </div>
    </section>

    <section class="messaging-layout mb-4 app-screen-block" data-messaging-page="1">
        <aside class="showcase-panel messaging-column">
            <div class="mb-4">
                <h2 class="showcase-panel-title">Recherche et filtres</h2>
            </div>

            <form method="GET" action="{{ route('workspace.messaging.index') }}" class="form-shell">
                <div class="form-grid-compact">
                    <div>
                        <label for="conversation_search">Recherche conversation</label>
                        <input id="conversation_search" name="conversation_search" type="text" value="{{ $conversationFilters['search'] }}" placeholder="Nom, email, message...">
                    </div>
                    <div>
                        <label for="contact_search">Recherche collaborateur</label>
                        <input id="contact_search" name="contact_search" type="text" value="{{ $directoryFilters['search'] }}" placeholder="Nom, matricule, fonction...">
                    </div>
                    <div>
                        <label for="conversation_direction_id">Direction</label>
                        <select id="conversation_direction_id" name="conversation_direction_id">
                            <option value="">Toutes</option>
                            @foreach ($filterOptions['directions'] as $direction)
                                <option value="{{ $direction->id }}" @selected((int) $conversationFilters['direction_id'] === (int) $direction->id)>{{ $direction->libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="conversation_service_id">Service</label>
                        <select id="conversation_service_id" name="conversation_service_id">
                            <option value="">Tous</option>
                            @foreach ($filterOptions['services'] as $service)
                                <option value="{{ $service->id }}" data-direction-id="{{ $service->direction_id }}" @selected((int) $conversationFilters['service_id'] === (int) $service->id)>{{ $service->libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="conversation_role">Rôle</label>
                        <select id="conversation_role" name="conversation_role">
                            <option value="">Tous</option>
                            @foreach ($filterOptions['roles'] as $role)
                                <option value="{{ $role }}" @selected($conversationFilters['role'] === $role)>{{ $roleLabel($role) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <label class="checkbox-pill flex-1 !mb-0">
                            <input type="checkbox" name="only_unread" value="1" @checked($conversationFilters['only_unread'])>
                            Non lus
                        </label>
                        <label class="checkbox-pill flex-1 !mb-0">
                            <input type="checkbox" name="favorites_only" value="1" @checked($conversationFilters['favorites'])>
                            Favoris
                        </label>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Appliquer</button>
                    <a class="btn btn-secondary" href="{{ route('workspace.messaging.index') }}">Réinitialiser</a>
                </div>
            </form>

            <div class="mt-5">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Conversations</h3>
                    </div>
                    <span class="anbg-badge anbg-badge-info">{{ $conversations->count() }}</span>
                </div>
                <div class="messaging-thread-list">
                    @forelse ($conversations as $conversation)
                        @php
                            /** @var User|null $otherUser */
                            $otherUser = $conversation->getAttribute('other_user');
                            $isActiveConversation = $activeConversation?->id === $conversation->id;
                            $latestMessage = $conversation->latestMessage;
                        @endphp
                        <a href="{{ route('workspace.messaging.index', ['conversation' => $conversation->id]) }}" class="messaging-thread-card {{ $isActiveConversation ? 'is-active' : '' }}">
                            <div class="flex items-start gap-3">
                                @if ($otherUser?->profile_photo_url)
                                    <img src="{{ $otherUser->profile_photo_url }}" alt="{{ $otherUser->name }}" class="h-11 w-11 rounded-2xl object-cover">
                                @else
                                    <span class="messaging-avatar">{{ $otherUser?->profile_initials ?? 'ME' }}</span>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="truncate text-sm font-semibold text-slate-900">{{ $conversation->getAttribute('display_name') }}</p>
                                        @if ((int) $conversation->getAttribute('unread_messages_count') > 0)
                                            <span class="messaging-unread-badge">{{ $conversation->getAttribute('unread_messages_count') }}</span>
                                        @endif
                                    </div>
                                    <p class="truncate text-xs text-slate-500">{{ $conversation->getAttribute('display_scope') }}</p>
                                    <p class="mt-1 truncate text-xs text-slate-600">
                                        {{ $latestMessage?->body ?: ($latestMessage?->attachment_original_name ?: 'Aucun message pour le moment.') }}
                                    </p>
                                    <div class="mt-2 flex items-center justify-between gap-2 text-[11px] text-slate-500">
                                        <span>{{ $latestMessage?->sent_at?->diffForHumans() ?? 'Nouveau' }}</span>
                                        @if ($conversation->getAttribute('is_favorite'))
                                            <span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-[10px]">Favori</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </a>
                    @empty
                        <x-ui.empty-state
                            title="Aucune conversation"
                            message="Choisissez un collaborateur dans l'annuaire pour démarrer un échange."
                            icon="inbox"
                            tone="info"
                            class="messaging-empty-state"
                        />
                    @endforelse
                </div>
            </div>

            <div class="mt-5">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Collaborateurs accessibles</h3>
                    </div>
                    <span class="anbg-badge anbg-badge-success">{{ $directoryUsers->count() }}</span>
                </div>
                <div class="space-y-2.5">
                    @forelse ($directoryUsers as $directoryUser)
                        <div class="messaging-contact-card">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-sm font-semibold text-slate-900">{{ $directoryUser->name }}</p>
                                    @php
                                        $presence = $directoryUser->presence_meta;
                                    @endphp
                                    <span class="anbg-badge {{ $presence['tone'] === 'success' ? 'anbg-badge-success' : ($presence['tone'] === 'info' ? 'anbg-badge-info' : 'anbg-badge-neutral') }} px-2 py-0.5 text-[10px]">{{ $presence['label'] }}</span>
                                </div>
                                <p class="truncate text-xs text-slate-500">{{ $directoryUser->agent_fonction ?: $directoryUser->roleLabel() }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $directoryUser->direction?->libelle ?? 'Sans direction' }} / {{ $directoryUser->service?->libelle ?? 'Sans service' }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('workspace.messaging.index', ['contact' => $directoryUser->id]) }}#messaging-profile-card" class="btn btn-secondary !px-3 !py-2">Voir</a>
                                <form method="POST" action="{{ route('workspace.messaging.direct', $directoryUser) }}">
                                    @csrf
                                    <button class="btn btn-primary !px-3 !py-2" type="submit">Écrire</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state
                            title="Aucun collaborateur"
                            message="Aucun collaborateur ne correspond aux filtres."
                            icon="users"
                            tone="info"
                            class="messaging-empty-state"
                        />
                    @endforelse
                </div>
            </div>
        </aside>

        <section class="showcase-panel messaging-column">
            @if ($activeConversation)
                @php
                    /** @var User|null $activeOtherUser */
                    $activeOtherUser = $activeConversation->getAttribute('other_user');
                    /** @var ConversationParticipant|null $otherParticipantState */
                    $otherParticipantState = $activeConversation->participantStates->firstWhere('user_id', $activeOtherUser?->id);
                @endphp
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="truncate text-lg font-semibold text-slate-950">{{ $activeConversation->getAttribute('display_name') }}</h2>
                            @if ($activeConversation->getAttribute('unread_messages_count') > 0)
                                <span class="anbg-badge anbg-badge-info">{{ $activeConversation->getAttribute('unread_messages_count') }} non lus</span>
                            @endif
                        </div>
                        <p class="text-sm text-slate-500">{{ $activeConversation->getAttribute('display_scope') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('workspace.messaging.favorite', $activeConversation) }}">
                            @csrf
                            <button class="btn btn-primary !px-3 !py-2" type="submit">
                                {{ $activeConversation->getAttribute('is_favorite') ? 'Retirer favori' : 'Ajouter favori' }}
                            </button>
                        </form>
                        @if ($activeOtherUser instanceof User)
                            <a class="btn btn-secondary !px-3 !py-2" href="{{ route('workspace.messaging.index', ['contact' => $activeOtherUser->id, 'conversation' => $activeConversation->id]) }}#messaging-profile-card">Fiche collaborateur</a>
                        @endif
                    </div>
                </div>

                <div
                    id="conversation-thread"
                    class="messaging-thread-panel"
                    data-conversation-id="{{ $activeConversation->id }}"
                    data-updates-url="{{ route('workspace.messaging.updates', $activeConversation) }}"
                    data-channel-name="messaging.conversation.{{ $activeConversation->id }}"
                    data-current-user-id="{{ $currentUser->id }}"
                >
                    @forelse ($activeConversation->messages as $message)
                        @php
                            $isMine = (int) $message->sender_id === (int) $currentUser->id;
                            $isSeen = $isMine && $otherParticipantState?->last_read_at && $message->sent_at && $message->sent_at->lte($otherParticipantState->last_read_at);
                        @endphp
                        <article
                            class="messaging-bubble {{ $isMine ? 'is-mine' : 'is-theirs' }}"
                            data-message-id="{{ $message->id }}"
                            data-sender-id="{{ $message->sender_id }}"
                            data-sent-at="{{ $message->sent_at?->toIso8601String() }}"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold">{{ $isMine ? 'Vous' : $message->sender?->name }}</span>
                                    <span class="text-[11px] opacity-75">{{ $message->sent_at?->format('d/m/Y H:i') }}</span>
                                </div>
                                @if ($isMine)
                                    <span class="text-[11px] font-medium opacity-80" data-message-seen-label>{{ $isSeen ? 'Vu' : 'Envoyé' }}</span>
                                @endif
                            </div>
                            @if (filled($message->body))
                                <p class="mt-2 whitespace-pre-line text-sm leading-6">{{ $message->body }}</p>
                            @endif
                            @if ($message->hasAttachment())
                                <div class="mt-3">
                                    <a class="messaging-attachment-link" href="{{ route('workspace.messaging.attachment.download', [$activeConversation, $message]) }}">
                                        <span class="font-medium">{{ $message->attachment_original_name }}</span>
                                        <span class="text-xs opacity-80">{{ number_format(((int) $message->attachment_size_bytes) / 1024, 1, ',', ' ') }} Ko</span>
                                    </a>
                                </div>
                            @endif
                        </article>
                    @empty
                        <x-ui.empty-state
                            title="Conversation ouverte"
                            message="Envoyez le premier message pour lancer l'échange."
                            icon="inbox"
                            tone="info"
                            class="messaging-empty-state"
                        />
                    @endforelse
                </div>

                <form method="POST" action="{{ route('workspace.messaging.send', $activeConversation) }}" class="mt-4 form-shell" enctype="multipart/form-data">
                    @csrf
                    <div class="form-grid-compact">
                        <div class="md:col-span-2">
                            <label for="body">Votre message</label>
                            <textarea id="body" name="body" rows="5" placeholder="Saisissez votre message, votre relance ou votre demande de coordination...">{{ old('body') }}</textarea>
                        </div>
                        <div>
                            <label for="attachment">Pièce jointe</label>
                            <input id="attachment" name="attachment" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.txt,.csv">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">Envoyer le message</button>
                    </div>
                </form>
            @else
                <x-ui.empty-state
                    title="Sélectionnez une conversation"
                    message="L'annuaire à gauche permet d'ouvrir rapidement un échange direct."
                    icon="users"
                    tone="info"
                    class="messaging-empty-state !h-full"
                />
            @endif
        </section>

        <aside id="messaging-profile-card" class="showcase-panel messaging-column">
            <div class="mb-4">
                <h2 class="showcase-panel-title">Fiche collaborateur</h2>
            </div>
            <div
                data-messaging-profile-content="1"
                data-profile-card-loading-label="Chargement de la fiche..."
            >
                @include('workspace.messaging.partials.profile-card', [
                    'contactCard' => $contactCard,
                    'currentUser' => $currentUser,
                    'activeConversationId' => $activeConversationId,
                ])
            </div>
        </aside>
    </section>

    {{-- Section organigramme retiree (2026-05-29) sur demande utilisateur. --}}
    </div>
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            function bindDependentServices(directionId, serviceId) {
                var directionInput = document.getElementById(directionId);
                var serviceInput = document.getElementById(serviceId);

                if (!directionInput || !serviceInput) {
                    return;
                }

                function syncServices() {
                    var selectedDirection = String(directionInput.value || '');
                    var selectedService = String(serviceInput.value || '');
                    var selectedStillVisible = false;

                    Array.prototype.forEach.call(serviceInput.options, function (option, index) {
                        if (index === 0) {
                            option.hidden = false;
                            option.disabled = false;
                            return;
                        }

                        var visible = selectedDirection === '' || String(option.getAttribute('data-direction-id') || '') === selectedDirection;
                        option.hidden = !visible;
                        option.disabled = !visible;

                        if (visible && option.value === selectedService) {
                            selectedStillVisible = true;
                        }
                    });

                    if (selectedService && !selectedStillVisible) {
                        serviceInput.value = '';
                    }
                }

                directionInput.addEventListener('change', syncServices);
                syncServices();
            }

            bindDependentServices('conversation_direction_id', 'conversation_service_id');
            bindDependentServices('org_direction_id', 'org_service_id');
        })();
    </script>
@endpush
