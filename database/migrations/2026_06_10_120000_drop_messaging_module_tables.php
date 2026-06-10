<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression complete du module Messagerie inter-agents.
 *
 * Retire les tables dediees (conversations, participants, messages). Les
 * permissions `messagerie.read` eventuellement stockees dans `platform_settings`
 * sont automatiquement ignorees a la lecture (filtre du catalogue de permissions),
 * aucune purge supplementaire n'est donc necessaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ordre FK-safe : messages -> participants -> conversations.
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }

    public function down(): void
    {
        // Module retire definitivement : migration non reversible.
    }
};
