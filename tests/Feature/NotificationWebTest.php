<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_filters_remain_submittable_and_preserve_read_state(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SERVICE]);
        $this->actingAs($user)->get(route('workspace.notifications.index'))
            ->assertOk()->assertSee('Aucune notification pour le moment');
        $user->notify(new WorkspaceModuleNotification([
            'title' => 'Suivi à examiner',
            'message' => 'Une information du module actions.',
            'module' => 'actions',
            'url' => route('workspace.actions.index'),
        ]));

        $response = $this->actingAs($user)->get(route('workspace.notifications.index', [
            'q' => 'Suivi', 'etat' => 'unread', 'module' => 'actions', 'per_page' => 25,
        ]))->assertOk();

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $form = '//form[@aria-label="Filtres des notifications"]';
        $this->assertSame(1, $xpath->query($form.'//button[@type="submit"]')->length);
        $this->assertSame('GET', $xpath->query($form)->item(0)->getAttribute('method'));
        $this->assertSame('Suivi', $xpath->query($form.'//input[@name="q"]')->item(0)->getAttribute('value'));
        $this->assertSame('unread', $xpath->query($form.'//select[@name="etat"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('actions', $xpath->query($form.'//select[@name="module"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('25', $xpath->query($form.'//select[@name="per_page"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertNull($user->notifications()->firstOrFail()->read_at);
        $this->get(route('workspace.notifications.index', ['q' => 'introuvable']))
            ->assertOk()->assertSee('Aucune notification dans cette vue');
    }

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
            ->assertSee('aria-label="Filtres des notifications"', false)
            ->assertSee('aria-label="Synthèse des notifications"', false)
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

    public function test_notification_center_filters_searches_and_paginates_the_complete_inbox(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
        ]);

        foreach (range(1, 17) as $index) {
            $user->notify(new WorkspaceModuleNotification([
                'title' => 'Action filtrable '.$index,
                'message' => 'Traitement courant du module actions.',
                'module' => 'actions',
                'status' => 'warning',
                'url' => route('workspace.actions.index'),
            ]));
        }

        $user->notify(new WorkspaceModuleNotification([
            'title' => 'Décision spéciale à retrouver',
            'message' => 'Validation prioritaire du PTA.',
            'module' => 'pta',
            'status' => 'critical',
            'url' => route('workspace.pta.index'),
        ]));
        $user->notify(new WorkspaceModuleNotification([
            'title' => 'Alerte technique exclue',
            'message' => 'Cette notification appartient au centre des alertes.',
            'module' => 'alertes',
            'status' => 'critical',
            'url' => route('workspace.notifications.index', ['tab' => 'alertes']),
        ]));

        $this->actingAs($user)
            ->get(route('workspace.notifications.index', [
                'module' => 'actions',
                'per_page' => 15,
            ]))
            ->assertOk()
            ->assertViewHas('notifications', function ($paginator): bool {
                return $paginator->total() === 17 && count($paginator->items()) === 15;
            })
            ->assertViewHas('notificationFilteredSummary', fn (array $summary): bool => $summary['total'] === 17)
            ->assertDontSee('Alerte technique exclue');

        $this->actingAs($user)
            ->get(route('workspace.notifications.index', [
                'q' => 'decision speciale',
                'module' => 'pta',
                'niveau' => 'critical',
            ]))
            ->assertOk()
            ->assertSee('Décision spéciale à retrouver')
            ->assertViewHas('notifications', fn ($paginator): bool => $paginator->total() === 1);
    }

    public function test_notification_center_neutralizes_array_query_values(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
        ]);

        $user->notify(new WorkspaceModuleNotification([
            'title' => 'Notification toujours accessible',
            'message' => 'Les filtres invalides ne cassent pas la page.',
            'module' => 'actions',
            'url' => route('workspace.actions.index'),
        ]));

        $this->actingAs($user)
            ->get(route('workspace.notifications.index', [
                'tab' => ['alertes'],
                'q' => ['invalide'],
                'etat' => ['unread'],
                'niveau' => ['critical'],
                'module' => ['actions'],
                'per_page' => [50],
                'page' => [2],
            ]))
            ->assertOk()
            ->assertViewHas('activeTab', 'notifications')
            ->assertSee('Notification toujours accessible');
    }
}
