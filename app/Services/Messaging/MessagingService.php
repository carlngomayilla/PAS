<?php

namespace App\Services\Messaging;

use App\Events\ConversationRead;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MessagingService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Conversation>
     */
    public function conversationSummaries(User $user, array $filters = []): Collection
    {
        $conversations = Conversation::query()
            ->whereHas('participantStates', fn (Builder $query) => $query->where('user_id', $user->id))
            ->with([
                'participants' => function ($query): void {
                    $query->select([
                        'users.id',
                        'users.name',
                        'users.email',
                        'users.role',
                        'users.profile_photo_path',
                        'users.agent_fonction',
                        'users.direction_id',
                        'users.service_id',
                    ])->with([
                        'direction:id,code,libelle',
                        'service:id,direction_id,code,libelle',
                    ]);
                },
                'latestMessage.sender:id,name,profile_photo_path',
                'participantStates' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get();

        $unreadCounts = $this->unreadCountsByConversation($user, $conversations->pluck('id'));

        return $conversations
            ->map(function (Conversation $conversation) use ($user, $unreadCounts): Conversation {
                $participantState = $conversation->participantStates->firstWhere('user_id', $user->id);
                $otherUser = $this->otherParticipant($conversation, $user);
                $unreadCount = (int) ($unreadCounts[$conversation->id] ?? 0);

                $conversation->setAttribute('participant_state', $participantState);
                $conversation->setAttribute('other_user', $otherUser);
                $conversation->setAttribute('unread_messages_count', $unreadCount);
                $conversation->setAttribute('is_favorite', (bool) ($participantState?->is_favorite ?? false));
                $conversation->setAttribute('display_name', $conversation->isDirect()
                    ? ($otherUser?->name ?? 'Conversation directe')
                    : ($conversation->title ?: 'Discussion de groupe'));
                $conversation->setAttribute('display_scope', $otherUser instanceof User
                    ? trim(implode(' / ', array_filter([
                        $otherUser->roleLabel(),
                        $otherUser->direction?->libelle,
                        $otherUser->service?->libelle,
                    ])))
                    : 'Conversation interne');

                return $conversation;
            })
            ->filter(fn (Conversation $conversation): bool => $this->matchesFilters($conversation, $filters))
            ->values();
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function recentConversations(User $user, int $limit = 5): Collection
    {
        return $this->conversationSummaries($user)
            ->take(max(1, $limit))
            ->values();
    }

    public function unreadCount(User $user): int
    {
        return (int) $this->unreadCountsByConversation(
            $user,
            ConversationParticipant::query()
                ->where('user_id', $user->id)
                ->pluck('conversation_id')
        )->sum();
    }

    public function findAccessibleConversation(User $user, int $conversationId): ?Conversation
    {
        $conversation = Conversation::query()
            ->whereKey($conversationId)
            ->whereHas('participantStates', fn (Builder $query) => $query->where('user_id', $user->id))
            ->with([
                'participants' => function ($query): void {
                    $query->select([
                        'users.id',
                        'users.name',
                        'users.email',
                        'users.role',
                        'users.profile_photo_path',
                        'users.agent_fonction',
                        'users.agent_telephone',
                        'users.agent_matricule',
                        'users.direction_id',
                        'users.service_id',
                    ])->with([
                        'direction:id,code,libelle',
                        'service:id,direction_id,code,libelle',
                    ]);
                },
                'participantStates',
                'messages' => fn ($query) => $query->with('sender:id,name,profile_photo_path')->orderByDesc('sent_at')->limit(80),
            ])
            ->first();

        if (! $conversation instanceof Conversation) {
            return null;
        }

        $unreadCounts = $this->unreadCountsByConversation($user, collect([$conversation->id]));
        $participantState = $conversation->participantStates->firstWhere('user_id', $user->id);

        $conversation->setAttribute('participant_state', $participantState);
        $conversation->setAttribute('other_user', $this->otherParticipant($conversation, $user));
        $conversation->setAttribute('unread_messages_count', (int) ($unreadCounts[$conversation->id] ?? 0));
        $conversation->setAttribute('is_favorite', (bool) ($participantState?->is_favorite ?? false));
        $conversation->setRelation('messages', $conversation->messages->sortBy('sent_at')->values());

        return $conversation;
    }

    public function ensureDirectConversation(User $sender, User $recipient): Conversation
    {
        $directKey = $this->directKey($sender, $recipient);
        $conversation = Conversation::query()->where('direct_key', $directKey)->first();

        if ($conversation instanceof Conversation) {
            return $conversation;
        }

        return DB::transaction(function () use ($sender, $recipient, $directKey): Conversation {
            $conversation = Conversation::query()->create([
                'type' => Conversation::TYPE_DIRECT,
                'direct_key' => $directKey,
                'created_by' => $sender->id,
            ]);

            $conversation->participantStates()->createMany([
                [
                    'user_id' => $sender->id,
                    'joined_at' => now(),
                    'last_read_at' => now(),
                ],
                [
                    'user_id' => $recipient->id,
                    'joined_at' => now(),
                ],
            ]);

            return $conversation;
        });
    }

    public function markConversationAsRead(Conversation $conversation, User $user): void
    {
        $timestamp = now();

        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['last_read_at' => $timestamp]);

        event(new ConversationRead((int) $conversation->id, (int) $user->id, $timestamp));
    }

    public function toggleFavorite(Conversation $conversation, User $user): bool
    {
        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $participant->is_favorite = ! $participant->is_favorite;
        $participant->save();

        return (bool) $participant->is_favorite;
    }

    public function sendMessage(Conversation $conversation, User $sender, string $body): Message
    {
        $timestamp = now();

        return DB::transaction(function () use ($conversation, $sender, $body, $timestamp): Message {
            $message = $conversation->messages()->create([
                'sender_id' => $sender->id,
                'body' => trim($body),
                'sent_at' => $timestamp,
            ]);

            Conversation::query()->whereKey($conversation->id)->update([
                'last_message_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $sender->id)
                ->update(['last_read_at' => $timestamp]);

            return $message;
        });
    }

    /**
     * @param  array{path:string,mime_type:?string,size_bytes:int,original_name:string,is_encrypted:bool}|null  $attachment
     */
    public function sendRichMessage(Conversation $conversation, User $sender, ?string $body, ?array $attachment = null): Message
    {
        $timestamp = now();
        $normalizedBody = trim((string) $body);

        return DB::transaction(function () use ($conversation, $sender, $attachment, $normalizedBody, $timestamp): Message {
            $message = $conversation->messages()->create([
                'sender_id' => $sender->id,
                'body' => $normalizedBody,
                'attachment_path' => $attachment['path'] ?? null,
                'attachment_original_name' => $attachment['original_name'] ?? null,
                'attachment_mime_type' => $attachment['mime_type'] ?? null,
                'attachment_size_bytes' => $attachment['size_bytes'] ?? null,
                'attachment_is_encrypted' => $attachment['is_encrypted'] ?? false,
                'sent_at' => $timestamp,
            ]);

            Conversation::query()->whereKey($conversation->id)->update([
                'last_message_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $sender->id)
                ->update(['last_read_at' => $timestamp]);

            $message = $message->load('sender:id,name,profile_photo_path');

            DB::afterCommit(static function () use ($message): void {
                event(new MessageSent($message));
            });

            return $message;
        });
    }

    /**
     * @return Collection<int, Message>
     */
    public function messagesAfter(Conversation $conversation, int $afterId): Collection
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', max(0, $afterId))
            ->with('sender:id,name,profile_photo_path')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();
    }

    private function otherParticipant(Conversation $conversation, User $user): ?User
    {
        /** @var User|null $other */
        $other = $conversation->participants->first(fn (User $participant): bool => $participant->id !== $user->id);

        return $other;
    }

    /**
     * @param  Collection<int, int>|Collection<int, mixed>  $conversationIds
     * @return Collection<int, int>
     */
    private function unreadCountsByConversation(User $user, Collection $conversationIds): Collection
    {
        $ids = $conversationIds
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Message::query()
            ->selectRaw('messages.conversation_id as conversation_id, count(*) as unread_count')
            ->join('conversation_participants as cp', function ($join) use ($user): void {
                $join->on('cp.conversation_id', '=', 'messages.conversation_id')
                    ->where('cp.user_id', '=', $user->id);
            })
            ->whereIn('messages.conversation_id', $ids->all())
            ->where('messages.sender_id', '!=', $user->id)
            ->whereRaw("messages.sent_at > COALESCE(cp.last_read_at, '1970-01-01 00:00:00')")
            ->groupBy('messages.conversation_id')
            ->pluck('unread_count', 'conversation_id')
            ->map(static fn ($count): int => (int) $count);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(Conversation $conversation, array $filters): bool
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $onlyUnread = (bool) ($filters['only_unread'] ?? false);
        $favoritesOnly = (bool) ($filters['favorites'] ?? false);
        $directionId = isset($filters['direction_id']) ? (int) $filters['direction_id'] : 0;
        $serviceId = isset($filters['service_id']) ? (int) $filters['service_id'] : 0;
        $role = trim((string) ($filters['role'] ?? ''));

        /** @var User|null $otherUser */
        $otherUser = $conversation->getAttribute('other_user');

        if ($search !== '') {
            $haystack = strtolower(trim(implode(' ', array_filter([
                (string) $conversation->getAttribute('display_name'),
                (string) $conversation->getAttribute('display_scope'),
                $otherUser?->email,
                $otherUser?->agent_fonction,
                $conversation->latestMessage?->body,
            ]))));

            if (! str_contains($haystack, strtolower($search))) {
                return false;
            }
        }

        if ($onlyUnread && ((int) $conversation->getAttribute('unread_messages_count')) < 1) {
            return false;
        }

        if ($favoritesOnly && ! (bool) $conversation->getAttribute('is_favorite')) {
            return false;
        }

        if ($directionId > 0 && (int) ($otherUser?->direction_id ?? 0) !== $directionId) {
            return false;
        }

        if ($serviceId > 0 && (int) ($otherUser?->service_id ?? 0) !== $serviceId) {
            return false;
        }

        if ($role !== '' && (string) ($otherUser?->role ?? '') !== $role) {
            return false;
        }

        return true;
    }

    private function directKey(User $sender, User $recipient): string
    {
        $pair = collect([$sender->id, $recipient->id])
            ->map(static fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        return implode(':', $pair);
    }
}
