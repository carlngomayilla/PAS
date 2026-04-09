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
                                <option value="{{ $service->id }}" @selected((int) $conversationFilters['service_id'] === (int) $service->id)>{{ $service->libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="conversation_role">Role</label>
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
                    <a class="btn btn-secondary" href="{{ route('workspace.messaging.index') }}">Reinitialiser</a>
                </div>
            </form>

            <div class="mt-5">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Conversations</h3>
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
                                        <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $conversation->getAttribute('display_name') }}</p>
                                        @if ((int) $conversation->getAttribute('unread_messages_count') > 0)
                                            <span class="messaging-unread-badge">{{ $conversation->getAttribute('unread_messages_count') }}</span>
                                        @endif
                                    </div>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $conversation->getAttribute('display_scope') }}</p>
                                    <p class="mt-1 truncate text-xs text-slate-600 dark:text-slate-300">
                                        {{ $latestMessage?->body ?: ($latestMessage?->attachment_original_name ?: 'Aucun message pour le moment.') }}
                                    </p>
                                    <div class="mt-2 flex items-center justify-between gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                                        <span>{{ $latestMessage?->sent_at?->diffForHumans() ?? 'Nouveau' }}</span>
                                        @if ($conversation->getAttribute('is_favorite'))
                                            <span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-[10px]">Favori</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="messaging-empty-state">
                            <p class="font-medium text-slate-900 dark:text-slate-100">Aucune conversation pour l instant.</p>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Choisissez un collaborateur dans l annuaire pour demarrer un echange.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="mt-5">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Collaborateurs accessibles</h3>
                    </div>
                    <span class="anbg-badge anbg-badge-success">{{ $directoryUsers->count() }}</span>
                </div>
                <div class="space-y-2.5">
                    @forelse ($directoryUsers as $directoryUser)
                        <div class="messaging-contact-card">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $directoryUser->name }}</p>
                                    @php
                                        $presence = $directoryUser->presence_meta;
                                    @endphp
                                    <span class="anbg-badge {{ $presence['tone'] === 'success' ? 'anbg-badge-success' : ($presence['tone'] === 'info' ? 'anbg-badge-info' : 'anbg-badge-neutral') }} px-2 py-0.5 text-[10px]">{{ $presence['label'] }}</span>
                                </div>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $directoryUser->agent_fonction ?: $directoryUser->roleLabel() }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $directoryUser->direction?->libelle ?? 'Sans direction' }} / {{ $directoryUser->service?->libelle ?? 'Sans service' }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('workspace.messaging.index', ['contact' => $directoryUser->id]) }}#messaging-profile-card" class="btn btn-secondary !px-3 !py-2">Voir</a>
                                <form method="POST" action="{{ route('workspace.messaging.direct', $directoryUser) }}">
                                    @csrf
                                    <button class="btn btn-primary !px-3 !py-2" type="submit">Ecrire</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="messaging-empty-state">
                            <p class="font-medium text-slate-900 dark:text-slate-100">Aucun collaborateur ne correspond aux filtres.</p>
                        </div>
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
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="truncate text-lg font-semibold text-slate-950 dark:text-slate-50">{{ $activeConversation->getAttribute('display_name') }}</h2>
                            @if ($activeConversation->getAttribute('unread_messages_count') > 0)
                                <span class="anbg-badge anbg-badge-info">{{ $activeConversation->getAttribute('unread_messages_count') }} non lus</span>
                            @endif
                        </div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $activeConversation->getAttribute('display_scope') }}</p>
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
                                    <span class="text-[11px] font-medium opacity-80" data-message-seen-label>{{ $isSeen ? 'Vu' : 'Envoye' }}</span>
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
                        <div class="messaging-empty-state">
                            <p class="font-medium text-slate-900 dark:text-slate-100">La conversation est ouverte.</p>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Envoyez le premier message pour lancer l echange.</p>
                        </div>
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
                            <label for="attachment">Piece jointe</label>
                            <input id="attachment" name="attachment" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.txt,.csv">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">Envoyer le message</button>
                    </div>
                </form>
            @else
                <div class="messaging-empty-state !h-full">
                    <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">Selectionnez une conversation ou un collaborateur</p>
                    <p class="mt-2 max-w-xl text-sm text-slate-500 dark:text-slate-400">L annuaire a gauche et l organigramme ci-dessous permettent d ouvrir rapidement un echange direct avec un interlocuteur du PAS.</p>
                </div>
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

    <section id="messaging-orgchart" class="showcase-panel app-screen-block">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Organigramme interactif</h2>
            </div>
            <a class="btn btn-secondary" href="{{ route('workspace.messaging.index') }}#messaging-orgchart">Ancre organigramme</a>
        </div>

        <form method="GET" action="{{ route('workspace.messaging.index') }}#messaging-orgchart" class="form-shell mb-5">
            <div class="form-grid-compact">
                <div>
                    <label for="org_search">Recherche organigramme</label>
                    <input id="org_search" name="org_search" type="text" value="{{ $orgFilters['search'] }}" placeholder="Nom, fonction, matricule...">
                </div>
                <div>
                    <label for="org_direction_id">Direction</label>
                    <select id="org_direction_id" name="org_direction_id">
                        <option value="">Toutes</option>
                        @foreach ($filterOptions['directions'] as $direction)
                            <option value="{{ $direction->id }}" @selected((int) $orgFilters['direction_id'] === (int) $direction->id)>{{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="org_service_id">Service</label>
                    <select id="org_service_id" name="org_service_id">
                        <option value="">Tous</option>
                        @foreach ($filterOptions['services'] as $service)
                            <option value="{{ $service->id }}" @selected((int) $orgFilters['service_id'] === (int) $service->id)>{{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="org_role">Role</label>
                    <select id="org_role" name="org_role">
                        <option value="">Tous</option>
                        @foreach ($filterOptions['roles'] as $role)
                            <option value="{{ $role }}" @selected($orgFilters['role'] === $role)>{{ $roleLabel($role) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Filtrer</button>
                <a class="btn btn-secondary" href="{{ route('workspace.messaging.index') }}#messaging-orgchart">Reinitialiser</a>
            </div>
        </form>

        @php
            $orgUserCount = collect($orgChart['users'] ?? [])->count();
        @endphp

        <div class="messaging-org-tree-shell" data-org-tree="1">
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <span class="anbg-badge anbg-badge-info">Liste arborescente</span>
                <span class="anbg-badge anbg-badge-neutral">Cliquez sur une branche pour la replier</span>
                <span class="anbg-badge anbg-badge-success">Cliquez sur une personne pour ouvrir sa fiche</span>
                <span class="anbg-badge anbg-badge-warning">{{ $orgUserCount }} profil(s) charges</span>
            </div>

            <div class="messaging-org-tree-toolbar">
                <div class="messaging-org-tree-toolbar-group">
                    <button type="button" class="btn btn-secondary !px-3 !py-2" data-org-expand-all>Tout deplier</button>
                    <button type="button" class="btn btn-secondary !px-3 !py-2" data-org-collapse-all>Tout replier</button>
                    <button type="button" class="btn btn-secondary !px-3 !py-2" data-org-reset-state>Reinitialiser l arbre</button>
                    <button type="button" class="btn btn-primary !px-3 !py-2" data-org-recenter>Recentrer la selection</button>
                </div>
                <div class="messaging-org-tree-toolbar-group messaging-org-tree-toolbar-search">
                    <label for="org_quick_search" class="sr-only">Recherche rapide dans la liste</label>
                    <input
                        id="org_quick_search"
                        type="search"
                        class="messaging-org-tree-search-input"
                        data-org-quick-search
                        placeholder="Filtrer la liste en direct..."
                        autocomplete="off"
                    >
                    <button type="button" class="btn btn-secondary !px-3 !py-2" data-org-clear-search disabled>Effacer</button>
                    <span class="anbg-badge anbg-badge-info whitespace-nowrap" data-org-search-count>{{ $orgUserCount }} profil(s) visible(s)</span>
                </div>
            </div>

            @if (! empty($orgChart['tree']))
                <div class="messaging-org-tree-viewport" data-org-tree-viewport>
                    <div class="messaging-org-tree-stage" data-org-tree-stage>
                        <ul class="messaging-org-tree-list" role="tree">
                            @foreach ($orgChart['tree'] as $node)
                                @include('workspace.messaging.partials.org-tree-node', [
                                    'node' => $node,
                                    'selectedUserId' => $selectedTreeUserId,
                                    'activeConversationId' => $activeConversationId,
                                ])
                            @endforeach
                        </ul>
                    </div>
                </div>
            @else
                <div class="messaging-empty-state">
                    <p class="font-medium text-slate-900 dark:text-slate-100">Aucun bloc organisationnel visible.</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Elargissez les filtres pour retrouver les collaborateurs attendus.</p>
                </div>
            @endif
        </div>
    </section>
    </div>
@endsection
