@php
    use App\Models\User;
@endphp

@if ($contactCard)
    @php
        /** @var User $cardUser */
        $cardUser = $contactCard['user'];
        $presence = $contactCard['presence'];
        $supervisor = $contactCard['supervisor'];
        $relatedUsers = $contactCard['related_users'];
        $conversationParam = ! empty($activeConversationId) ? ['conversation' => (int) $activeConversationId] : [];
    @endphp
    <div class="messaging-profile-card">
        <div class="flex items-start gap-4">
            @if ($cardUser->profile_photo_url)
                <img src="{{ $cardUser->profile_photo_url }}" alt="{{ $cardUser->name }}" class="h-16 w-16 rounded-3xl object-cover">
            @else
                <span class="messaging-avatar !h-16 !w-16 !text-lg">{{ $cardUser->profile_initials }}</span>
            @endif
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate text-base font-semibold text-slate-950 dark:text-slate-50">{{ $cardUser->name }}</p>
                    <span class="anbg-badge {{ $presence['tone'] === 'success' ? 'anbg-badge-success' : ($presence['tone'] === 'info' ? 'anbg-badge-info' : 'anbg-badge-neutral') }}">{{ $presence['label'] }}</span>
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $cardUser->agent_fonction ?: 'Fonction non renseignee' }}</p>
            </div>
        </div>

        <div class="mt-4 showcase-data-list">
            <div class="showcase-data-point">
                <p class="showcase-data-key">Email</p>
                <p class="showcase-data-value">{{ $cardUser->email }}</p>
            </div>
            <div class="showcase-data-point">
                <p class="showcase-data-key">Telephone</p>
                <p class="showcase-data-value">{{ $cardUser->agent_telephone ?: 'Non renseigne' }}</p>
            </div>
            <div class="showcase-data-point">
                <p class="showcase-data-key">Matricule</p>
                <p class="showcase-data-value">{{ $cardUser->agent_matricule ?: 'Non renseigne' }}</p>
            </div>
            <div class="showcase-data-point">
                <p class="showcase-data-key">Direction</p>
                <p class="showcase-data-value">{{ $cardUser->direction?->libelle ?? 'Aucune' }}</p>
            </div>
            <div class="showcase-data-point">
                <p class="showcase-data-key">Service</p>
                <p class="showcase-data-value">{{ $cardUser->service?->libelle ?? 'Aucun' }}</p>
            </div>
            <div class="showcase-data-point">
                <p class="showcase-data-key">Role PAS</p>
                <p class="showcase-data-value">{{ $cardUser->roleLabel() }}</p>
            </div>
            <div class="showcase-data-point">
                <p class="showcase-data-key">Responsable hierarchique</p>
                <p class="showcase-data-value">{{ $supervisor?->name ?? 'Non determine' }}</p>
            </div>
            <div class="showcase-data-point">
                <p class="showcase-data-key">Derniere activite</p>
                <p class="showcase-data-value">{{ $presence['last_activity']?->diffForHumans() ?? 'Aucune session recente' }}</p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            @if ((int) $cardUser->id !== (int) $currentUser->id)
                <form method="POST" action="{{ route('workspace.messaging.direct', $cardUser) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Envoyer un message</button>
                </form>
            @else
                <a class="btn btn-secondary" href="{{ route('workspace.profile.edit') }}">Voir mon profil</a>
            @endif
        </div>
    </div>

    <div class="mt-5">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Collaborateurs lies</h3>
            <span class="anbg-badge anbg-badge-neutral">{{ $relatedUsers->count() }}</span>
        </div>
        <div class="space-y-2.5">
            @forelse ($relatedUsers as $relatedUser)
                <a
                    href="{{ route('workspace.messaging.index', array_merge(['contact' => $relatedUser->id], $conversationParam)) }}#messaging-profile-card"
                    class="messaging-related-user"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ $relatedUser->name }}</p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $relatedUser->agent_fonction ?: $relatedUser->roleLabel() }}</p>
                    </div>
                    <span class="text-xs text-slate-400">Voir</span>
                </a>
            @empty
                <p class="text-sm text-slate-500 dark:text-slate-400">Aucun collaborateur lie dans le meme perimetre.</p>
            @endforelse
        </div>
    </div>
@else
    <div class="messaging-empty-state">
        <p class="font-medium text-slate-900 dark:text-slate-100">Aucun collaborateur selectionne.</p>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Selectionnez un contact pour afficher sa fiche detaillee et lancer un echange.</p>
    </div>
@endif
