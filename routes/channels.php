<?php

use App\Models\ConversationParticipant;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('messaging.conversation.{conversationId}', function ($user, $conversationId) {
    return ConversationParticipant::query()
        ->where('conversation_id', (int) $conversationId)
        ->where('user_id', (int) $user->id)
        ->exists();
});
