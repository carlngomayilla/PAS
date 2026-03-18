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
}
