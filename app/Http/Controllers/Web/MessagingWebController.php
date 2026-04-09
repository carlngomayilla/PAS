<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\MessagingDirectoryService;
use App\Services\Messaging\MessagingService;
use App\Services\Security\SecureMessageAttachmentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessagingWebController extends Controller
{
    public function __construct(
        private readonly MessagingService $messagingService,
        private readonly MessagingDirectoryService $directoryService
    ) {
    }

    public function index(Request $request): View
    {
        $user = $this->authUser($request);
        $this->denyUnlessMessagingReader($user);

        $conversationFilters = [
            'search' => (string) $request->query('conversation_search', ''),
            'only_unread' => $request->boolean('only_unread'),
            'favorites' => $request->boolean('favorites_only'),
            'direction_id' => $request->integer('conversation_direction_id'),
            'service_id' => $request->integer('conversation_service_id'),
            'role' => (string) $request->query('conversation_role', ''),
        ];

        $directoryFilters = [
            'search' => (string) $request->query('contact_search', ''),
            'direction_id' => $request->integer('contact_direction_id'),
            'service_id' => $request->integer('contact_service_id'),
            'role' => (string) $request->query('contact_role', ''),
        ];

        $orgFilters = [
            'search' => (string) $request->query('org_search', ''),
            'direction_id' => $request->integer('org_direction_id'),
            'service_id' => $request->integer('org_service_id'),
            'role' => (string) $request->query('org_role', ''),
        ];

        $conversations = $this->messagingService->conversationSummaries($user, $conversationFilters);
        $conversationId = $request->integer('conversation');
        if ($conversationId < 1 && $conversations->isNotEmpty()) {
            $conversationId = (int) $conversations->first()->id;
        }

        $activeConversation = $conversationId > 0
            ? $this->messagingService->findAccessibleConversation($user, $conversationId)
            : null;

        if ($activeConversation instanceof Conversation) {
            $this->messagingService->markConversationAsRead($activeConversation, $user);
            $conversations = $this->messagingService->conversationSummaries($user, $conversationFilters);
            $activeConversation = $this->messagingService->findAccessibleConversation($user, $activeConversation->id);
        }

        $directoryUsers = $this->directoryService->visibleUsers($user, $directoryFilters, 18)
            ->reject(fn (User $candidate): bool => $candidate->id === $user->id)
            ->values();

        $selectedUser = $activeConversation?->getAttribute('other_user');
        $contactId = $request->integer('contact');
        if (! $selectedUser instanceof User && $contactId > 0) {
            $candidate = User::query()->find($contactId);
            if ($candidate instanceof User && $this->directoryService->canContactUser($user, $candidate)) {
                $selectedUser = $candidate;
            }
        }

        if (! $selectedUser instanceof User && $directoryUsers->isNotEmpty()) {
            /** @var User $firstDirectoryUser */
            $firstDirectoryUser = $directoryUsers->first();
            $selectedUser = $firstDirectoryUser;
        }

        $contactCard = $this->resolveContactCard($user, $selectedUser);
        $orgChart = $this->directoryService->orgChart($user, $orgFilters);
        $filterOptions = $this->filterOptions($user);

        return view('workspace.messaging.index', [
            'title' => 'Messagerie interne',
            'currentUser' => $user,
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'directoryUsers' => $directoryUsers,
            'contactCard' => $contactCard,
            'orgChart' => $orgChart,
            'filterOptions' => $filterOptions,
            'conversationFilters' => $conversationFilters,
            'directoryFilters' => $directoryFilters,
            'orgFilters' => $orgFilters,
        ]);
    }

    public function profileCard(Request $request, User $target): JsonResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessMessagingReader($user);
        $contactCard = $this->resolveContactCard($user, $target);

        if ($contactCard === null) {
            abort(403, 'Contact non autorise.');
        }

        $html = view('workspace.messaging.partials.profile-card', [
            'contactCard' => $contactCard,
            'currentUser' => $user,
            'activeConversationId' => $request->integer('conversation'),
        ])->render();

        return response()->json([
            'user_id' => (int) ($contactCard['user']->id ?? 0),
            'html' => $html,
            'can_message' => (int) ($contactCard['user']->id ?? 0) !== (int) $user->id,
        ]);
    }

    public function startDirect(Request $request, User $target): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessMessagingReader($user);

        if (! $this->directoryService->canContactUser($user, $target)) {
            abort(403, 'Contact non autorise.');
        }

        $conversation = $this->messagingService->ensureDirectConversation($user, $target);

        return redirect()
            ->route('workspace.messaging.index', ['conversation' => $conversation->id])
            ->with('success', 'Conversation ouverte.');
    }

    public function send(
        Request $request,
        Conversation $conversation,
        SecureMessageAttachmentStorage $attachmentStorage
    ): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessMessagingReader($user);
        $activeConversation = $this->messagingService->findAccessibleConversation($user, $conversation->id);
        if (! $activeConversation instanceof Conversation) {
            abort(403, 'Conversation non autorisee.');
        }

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,csv'],
        ]);

        $body = trim((string) ($validated['body'] ?? ''));
        $file = $request->file('attachment');
        if ($body === '' && ! $file) {
            return redirect()
                ->route('workspace.messaging.index', ['conversation' => $activeConversation->id])
                ->withErrors(['body' => 'Saisissez un message ou joignez un fichier.'])
                ->withInput();
        }

        $attachment = $file ? $attachmentStorage->store($file, 'messagerie/attachments/'.date('Y/m')) : null;

        $this->messagingService->sendRichMessage($activeConversation, $user, $body, $attachment);

        return redirect()
            ->route('workspace.messaging.index', ['conversation' => $activeConversation->id])
            ->with('success', 'Message envoye.');
    }

    public function updates(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessMessagingReader($user);
        $activeConversation = $this->messagingService->findAccessibleConversation($user, $conversation->id);
        if (! $activeConversation instanceof Conversation) {
            abort(403, 'Conversation non autorisee.');
        }

        $afterId = max(0, $request->integer('after'));
        $messages = $this->messagingService->messagesAfter($activeConversation, $afterId);

        $this->messagingService->markConversationAsRead($activeConversation, $user);
        $freshConversation = $this->messagingService->findAccessibleConversation($user, $activeConversation->id);

        return response()->json([
            'messages' => $this->serializeMessages($messages, $user, $freshConversation),
            'other_last_read_at' => optional($this->otherParticipantState($freshConversation, $user)?->last_read_at)?->toIso8601String(),
            'conversation_id' => $activeConversation->id,
            'last_message_id' => (int) ($messages->last()?->id ?? $afterId),
        ]);
    }

    public function downloadAttachment(
        Request $request,
        Conversation $conversation,
        Message $message,
        SecureMessageAttachmentStorage $attachmentStorage
    ): StreamedResponse {
        $user = $this->authUser($request);
        $this->denyUnlessMessagingReader($user);
        $activeConversation = $this->messagingService->findAccessibleConversation($user, $conversation->id);
        if (! $activeConversation instanceof Conversation || (int) $message->conversation_id !== (int) $activeConversation->id) {
            abort(403, 'Piece jointe non autorisee.');
        }

        return $attachmentStorage->download($message);
    }

    public function toggleFavorite(Request $request, Conversation $conversation): RedirectResponse
    {
        $user = $this->authUser($request);
        $this->denyUnlessMessagingReader($user);
        $activeConversation = $this->messagingService->findAccessibleConversation($user, $conversation->id);
        if (! $activeConversation instanceof Conversation) {
            abort(403, 'Conversation non autorisee.');
        }

        $isFavorite = $this->messagingService->toggleFavorite($activeConversation, $user);

        return redirect()
            ->route('workspace.messaging.index', ['conversation' => $activeConversation->id])
            ->with('success', $isFavorite ? 'Conversation ajoutee aux favoris.' : 'Conversation retiree des favoris.');
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(User $user): array
    {
        $visibleUsers = $this->directoryService->visibleUsers($user, [], 0);

        return [
            'directions' => $visibleUsers
                ->pluck('direction')
                ->filter()
                ->unique('id')
                ->sortBy('libelle')
                ->values(),
            'services' => $visibleUsers
                ->pluck('service')
                ->filter()
                ->unique('id')
                ->sortBy('libelle')
                ->values(),
            'roles' => collect([
                User::ROLE_ADMIN,
                User::ROLE_DG,
                User::ROLE_CABINET,
                User::ROLE_PLANIFICATION,
                User::ROLE_DIRECTION,
                User::ROLE_SERVICE,
                User::ROLE_AGENT,
            ])->filter(fn (string $role): bool => $visibleUsers->contains(fn (User $row): bool => $row->role === $role))->values(),
        ];
    }

    private function authUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    private function denyUnlessMessagingReader(User $user): void
    {
        if ($user->hasPermission('messagerie.read')) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveContactCard(User $viewer, ?User $subject): ?array
    {
        return $this->directoryService->collaboratorCard($viewer, $subject);
    }

    private function otherParticipantState(?Conversation $conversation, User $user): ?\App\Models\ConversationParticipant
    {
        if (! $conversation instanceof Conversation) {
            return null;
        }

        /** @var \App\Models\ConversationParticipant|null $state */
        $state = $conversation->participantStates->first(fn ($participantState): bool => (int) $participantState->user_id !== (int) $user->id);

        return $state;
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function serializeMessages(Collection $messages, User $user, ?Conversation $conversation): array
    {
        $otherLastReadAt = $this->otherParticipantState($conversation, $user)?->last_read_at;

        return $messages->map(function (Message $message) use ($otherLastReadAt, $user): array {
            $isMine = (int) $message->sender_id === (int) $user->id;

            return [
                'id' => (int) $message->id,
                'body' => (string) $message->body,
                'is_mine' => $isMine,
                'sent_at_label' => optional($message->sent_at)->format('d/m/Y H:i'),
                'sent_at_iso' => optional($message->sent_at)->toIso8601String(),
                'sender_name' => $isMine ? 'Vous' : (string) ($message->sender?->name ?? 'Collaborateur'),
                'is_seen' => $isMine && $otherLastReadAt && $message->sent_at && $message->sent_at->lte($otherLastReadAt),
                'attachment' => $message->hasAttachment() ? [
                    'name' => $message->attachment_original_name,
                    'download_url' => route('workspace.messaging.attachment.download', [$conversation ?? $message->conversation_id, $message]),
                    'size_label' => $this->formatBytes((int) $message->attachment_size_bytes),
                ] : null,
            ];
        })->all();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' o';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', ' ').' Ko';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', ' ').' Mo';
    }
}
