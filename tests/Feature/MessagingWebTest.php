<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class MessagingWebTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_start_direct_conversation_and_send_message(): void
    {
        $admin = $this->createAdminUser([
            'email' => 'admin.messaging@anbg.test',
        ]);
        $recipient = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $response = $this->actingAs($admin)
            ->post(route('workspace.messaging.direct', $recipient));

        $response->assertRedirect();

        $conversation = Conversation::query()->firstOrFail();
        $this->assertSame('direct', $conversation->type);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
        ]);

        $this->actingAs($admin)
            ->post(route('workspace.messaging.send', $conversation), [
                'body' => 'Bonjour Robert, merci de confirmer la situation du PTA SFC.',
            ])
            ->assertRedirect(route('workspace.messaging.index', ['conversation' => $conversation->id]));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $admin->id,
            'body' => 'Bonjour Robert, merci de confirmer la situation du PTA SFC.',
        ]);

        $this->actingAs($recipient)
            ->get('/workspace/pilotage')
            ->assertOk()
            ->assertSee('1 message(s) non lus');
    }

    public function test_service_user_sees_only_allowed_contacts_in_messaging_directory(): void
    {
        $serviceUser = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $response = $this->actingAs($serviceUser)->get('/workspace/messagerie');

        $response->assertOk();
        $response->assertSee('MATTEYA Aicha');
        $response->assertSee('Ingrid');
        $response->assertDontSee('Arnold MINDZELI');
    }

    public function test_messaging_org_tree_displays_clickable_profile_nodes(): void
    {
        $admin = $this->createAdminUser([
            'email' => 'admin.orgtree@anbg.test',
        ]);
        $target = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $response = $this->actingAs($admin)
            ->get(route('workspace.messaging.index', ['contact' => $target->id]));

        $response->assertOk();
        $response->assertSee('data-org-tree="1"', false);
        $response->assertSee('Liste arborescente');
        $response->assertSee('Tout deplier');
        $response->assertSee('Directions');
        $response->assertSee('Services');
        $response->assertSee('Agents');
        $response->assertSee('Reinitialiser l arbre');
        $response->assertSee('data-org-quick-search', false);
        $response->assertSee('data-org-clear-search', false);
        $response->assertSee('profil(s) visible(s)');
        $response->assertDontSee('data-org-layout-toggle', false);
        $response->assertDontSee('Vue organigramme');
        $response->assertDontSee('Arbre compact');
        $response->assertDontSee('data-org-zoom-in', false);
        $response->assertSee('data-org-recenter', false);
        $response->assertSee(route('workspace.messaging.index', ['contact' => $target->id]).'#messaging-profile-card', false);
        $response->assertSee('DAF');
        $response->assertSee('messaging-org-tree-manager-link level-direction', false);
        $response->assertSee('messaging-org-tree-node is-user level-agent', false);
        $response->assertSee($target->name);
        $response->assertSee('Envoyer un message');
    }

    public function test_messaging_profile_card_endpoint_returns_html_fragment_for_contact(): void
    {
        $admin = $this->createAdminUser([
            'email' => 'admin.profilecard@anbg.test',
        ]);
        $target = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $response = $this->actingAs($admin)
            ->getJson(route('workspace.messaging.profile.card', ['target' => $target->id]));

        $response->assertOk()
            ->assertJsonPath('user_id', $target->id)
            ->assertJsonPath('can_message', true);

        $this->assertStringContainsString($target->name, (string) $response->json('html'));
        $this->assertStringContainsString('Envoyer un message', (string) $response->json('html'));
    }

    public function test_opening_messaging_marks_active_conversation_as_read(): void
    {
        $admin = $this->createAdminUser([
            'email' => 'admin.read@anbg.test',
        ]);
        $recipient = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();
        $conversation = Conversation::query()->create([
            'type' => Conversation::TYPE_DIRECT,
            'direct_key' => collect([$admin->id, $recipient->id])->sort()->implode(':'),
            'created_by' => $admin->id,
            'last_message_at' => now(),
        ]);

        $conversation->participantStates()->createMany([
            [
                'user_id' => $admin->id,
                'joined_at' => now(),
                'last_read_at' => now(),
            ],
            [
                'user_id' => $recipient->id,
                'joined_at' => now()->subHour(),
                'last_read_at' => null,
            ],
        ]);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $admin->id,
            'body' => 'Message de test non lu',
            'sent_at' => now(),
        ]);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
            'last_read_at' => null,
        ]);

        $this->actingAs($recipient)
            ->get(route('workspace.messaging.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Message de test non lu');

        $this->assertDatabaseMissing('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
            'last_read_at' => null,
        ]);
    }

    public function test_admin_can_send_attachment_and_download_it(): void
    {
        $admin = $this->createAdminUser([
            'email' => 'admin.attach@anbg.test',
        ]);
        $recipient = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $this->actingAs($admin)->post(route('workspace.messaging.direct', $recipient))->assertRedirect();

        $conversation = Conversation::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('workspace.messaging.send', $conversation), [
                'body' => 'Veuillez trouver le point de suivi.',
                'attachment' => UploadedFile::fake()->create('point-suivi.txt', 4, 'text/plain'),
            ])
            ->assertRedirect(route('workspace.messaging.index', ['conversation' => $conversation->id]));

        /** @var Message $message */
        $message = Message::query()->where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame('point-suivi.txt', $message->attachment_original_name);
        $this->assertNotNull($message->attachment_path);
        $this->assertGreaterThan(0, (int) $message->attachment_size_bytes);

        $response = $this->actingAs($recipient)
            ->get(route('workspace.messaging.attachment.download', [$conversation, $message]));

        $response->assertOk();
        $this->assertStringContainsString('point-suivi.txt', (string) $response->headers->get('content-disposition'));
    }

    public function test_updates_endpoint_returns_incremental_messages_and_marks_them_as_read(): void
    {
        $admin = $this->createAdminUser([
            'email' => 'admin.updates@anbg.test',
        ]);
        $recipient = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $conversation = Conversation::query()->create([
            'type' => Conversation::TYPE_DIRECT,
            'direct_key' => collect([$admin->id, $recipient->id])->sort()->implode(':'),
            'created_by' => $admin->id,
            'last_message_at' => now(),
        ]);

        $conversation->participantStates()->createMany([
            [
                'user_id' => $admin->id,
                'joined_at' => now(),
                'last_read_at' => now(),
            ],
            [
                'user_id' => $recipient->id,
                'joined_at' => now()->subHour(),
                'last_read_at' => null,
            ],
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $admin->id,
            'body' => 'Nouveau point pour la coordination.',
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($recipient)
            ->getJson(route('workspace.messaging.updates', ['conversation' => $conversation->id, 'after' => 0]));

        $response
            ->assertOk()
            ->assertJsonPath('messages.0.id', $message->id)
            ->assertJsonPath('messages.0.body', 'Nouveau point pour la coordination.');

        $this->assertDatabaseMissing('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
            'last_read_at' => null,
        ]);
    }
}
