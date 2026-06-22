<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_open_notification_and_mark_it_as_read(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
        ]);

        $user->notify(new WorkspaceModuleNotification([
            'title' => 'Action soumise',
            'message' => 'Une action attend votre validation.',
            'module' => 'actions',
            'url' => route('workspace.actions.index'),
        ]));

        $notification = $user->notifications()->latest()->firstOrFail();
        $this->assertNull($notification->read_at);

        $this->actingAs($user)
            ->get(route('workspace.notifications.read', $notification->id))
            ->assertRedirect(route('workspace.actions.index'));

        $notification->refresh();
        $this->assertNotNull($notification->read_at);
    }

    public function test_notification_opening_ignores_external_redirect_url(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
        ]);

        $user->notify(new WorkspaceModuleNotification([
            'title' => 'Lien externe',
            'message' => 'URL invalide pour le centre de notifications.',
            'module' => 'actions',
            'url' => 'https://example.test/phishing',
        ]));

        $notification = $user->notifications()->latest()->firstOrFail();

        $this->actingAs($user)
            ->get(route('workspace.notifications.read', $notification->id))
            ->assertRedirect(route('dashboard'));

        $notification->refresh();
        $this->assertNotNull($notification->read_at);
    }

    public function test_user_can_mark_all_notifications_as_read(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
        ]);

        $user->notify(new WorkspaceModuleNotification([
            'title' => 'PAO soumis',
            'message' => 'Validation requise.',
            'module' => 'pao',
            'url' => route('workspace.pao.index'),
        ]));
        $user->notify(new WorkspaceModuleNotification([
            'title' => 'PTA soumis',
            'message' => 'Validation requise.',
            'module' => 'pta',
            'url' => route('workspace.pta.index'),
        ]));

        $this->assertSame(2, $user->unreadNotifications()->count());

        $this->actingAs($user)
            ->post(route('workspace.notifications.read_all'))
            ->assertRedirect();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_alert_notifications_are_not_shown_or_marked_from_notification_tab(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
        ]);

        $user->notify(new WorkspaceModuleNotification([
            'title' => 'Action soumise',
            'message' => 'Une action attend votre validation.',
            'module' => 'actions',
            'url' => route('workspace.actions.index'),
        ]));
        $user->notify(new WorkspaceModuleNotification([
            'title' => 'Action en retard',
            'message' => 'Cette action a dépassé son échéance.',
            'module' => 'alertes',
            'url' => route('workspace.notifications.index', ['tab' => 'alertes']),
        ]));

        $this->actingAs($user)
            ->get(route('workspace.notifications.index'))
            ->assertOk()
            ->assertSee('Action soumise')
            ->assertDontSee('Action en retard')
            ->assertSee('1 notification(s) non lue(s)');

        $this->actingAs($user)
            ->post(route('workspace.notifications.read_all'))
            ->assertRedirect();

        $notifications = $user->fresh()->notifications()->get();

        $this->assertNotNull($notifications->firstWhere('data.module', 'actions')?->read_at);
        $this->assertNull($notifications->firstWhere('data.module', 'alertes')?->read_at);
    }
}
