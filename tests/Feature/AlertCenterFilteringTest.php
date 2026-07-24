<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use App\Services\Alerting\AlertRuleSettings;
use App\Support\SchemaIntrospectionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertCenterFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_center_filters_by_warning_level(): void
    {
        $fixture = $this->createFixture();

        $this->createAlert($fixture['action'], 'warning', 'Alerte vigilance visible');
        $this->createAlert($fixture['action'], 'critical', 'Alerte critique masquee');

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.notifications.index', [
                'tab' => 'alertes',
                'niveau' => 'warning',
                'limit' => 100,
            ]))
            ->assertOk()
            ->assertSee('Alerte vigilance visible')
            ->assertDontSee('Alerte critique masquee')
            ->assertSee('Vigilance');
    }

    public function test_alert_center_filters_by_read_state(): void
    {
        $fixture = $this->createFixture();

        $readLog = $this->createAlert($fixture['action'], 'warning', 'Alerte deja lue');
        $this->createAlert($fixture['action'], 'critical', 'Alerte encore non lue');

        $readAlert = app(AlertCenterService::class)
            ->buildForUser($fixture['admin'], 100)
            ->first(fn (array $alert): bool => (int) ($alert['source_id'] ?? 0) === (int) $readLog->id);

        $this->assertIsArray($readAlert);
        app(AlertReadService::class)->markAlertAsRead($fixture['admin'], $readAlert);

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.notifications.index', [
                'tab' => 'alertes',
                'etat' => 'unread',
                'limit' => 100,
            ]))
            ->assertOk()
            ->assertSee('Alerte encore non lue')
            ->assertDontSee('Alerte deja lue');
    }

    public function test_alert_history_view_lists_read_alert_snapshots(): void
    {
        $fixture = $this->createFixture();

        $log = $this->createAlert($fixture['action'], 'warning', 'Alerte historique visible');

        $readAlert = app(AlertCenterService::class)
            ->buildForUser($fixture['admin'], 100)
            ->first(fn (array $alert): bool => (int) ($alert['source_id'] ?? 0) === (int) $log->id);

        $this->assertIsArray($readAlert);
        app(AlertReadService::class)->markAlertAsRead($fixture['admin'], $readAlert);

        $this->assertDatabaseHas('alert_reads', [
            'user_id' => $fixture['admin']->id,
            'source_type' => 'action_log',
            'source_id' => $log->id,
            'niveau' => 'warning',
            'message' => 'Alerte historique visible',
        ]);

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.notifications.index', [
                'tab' => 'alertes',
                'vue' => 'historique',
                'niveau' => 'warning',
                'limit' => 100,
            ]))
            ->assertOk()
            ->assertSee('Historique')
            ->assertSee('Alerte historique visible')
            ->assertSee('Lue le')
            ->assertSee('Ouvrir')
            ->assertDontSee('Aucun historique');
    }

    public function test_read_all_alerts_stores_history_snapshots(): void
    {
        $fixture = $this->createFixture();

        $this->createAlert($fixture['action'], 'warning', 'Alerte lue par lot');
        $this->createAlert($fixture['action'], 'critical', 'Alerte critique lue par lot');

        $this->actingAs($fixture['admin'])
            ->post(route('workspace.alertes.read_all', ['limit' => 100]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('alert_reads', [
            'user_id' => $fixture['admin']->id,
            'niveau' => 'warning',
            'message' => 'Alerte lue par lot',
        ]);
        $this->assertDatabaseHas('alert_reads', [
            'user_id' => $fixture['admin']->id,
            'niveau' => 'critical',
            'message' => 'Alerte critique lue par lot',
        ]);

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.notifications.index', [
                'tab' => 'alertes',
                'vue' => 'historique',
                'limit' => 100,
            ]))
            ->assertOk()
            ->assertSee('Alerte lue par lot')
            ->assertSee('Alerte critique lue par lot');
    }

    public function test_alert_history_filters_conforme_snapshots(): void
    {
        $fixture = $this->createFixture();

        app(AlertReadService::class)->markAlertAsRead($fixture['admin'], [
            'source_type' => 'action_log',
            'source_id' => 999,
            'niveau' => 'conforme',
            'niveau_label' => 'Conforme',
            'type_label' => 'Controle',
            'titre' => 'Alerte conforme archivee',
            'message' => 'Situation conforme conservee dans l historique.',
            'target_url' => route('workspace.notifications.index', ['tab' => 'alertes']),
            'fingerprint' => 'conforme:test:999',
        ]);

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.notifications.index', [
                'tab' => 'alertes',
                'vue' => 'historique',
                'niveau' => 'conforme',
                'limit' => 100,
            ]))
            ->assertOk()
            ->assertSee('Conforme')
            ->assertSee('Alerte conforme archivee');
    }

    public function test_alert_center_uses_configurable_overdue_thresholds(): void
    {
        $fixture = $this->createFixture();

        SchemaIntrospectionCache::flush();
        $alertRuleSettings = app(AlertRuleSettings::class);
        $alertRuleSettings->update(array_merge($alertRuleSettings->defaults(), [
            'overdue_critical_days' => 3,
        ]));
        $this->assertDatabaseHas('platform_settings', [
            'group' => 'alert_rules',
            'key' => 'overdue_critical_days',
            'value' => '3',
        ]);
        $this->assertSame(3, $alertRuleSettings->overdueCriticalDays());

        $fixture['action']->forceFill([
            'date_echeance' => now()->subDays(4)->toDateString(),
            'date_fin' => now()->subDays(4)->toDateString(),
            'progression_reelle' => 40,
            'statut_dynamique' => 'en_cours',
        ])->save();

        $alert = app(AlertCenterService::class)
            ->buildForUser($fixture['admin'], 100)
            ->first(fn (array $item): bool => ($item['source_type'] ?? '') === 'action_overdue');

        $this->assertIsArray($alert);
        $this->assertSame('critical', $alert['niveau']);
    }

    public function test_workspace_alertes_route_preserves_filters(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.alertes', [
                'niveau' => 'critical',
                'etat' => 'unread',
                'vue' => 'historique',
                'limit' => 25,
            ]))
            ->assertRedirect(route('workspace.notifications.index', [
                'tab' => 'alertes',
                'limit' => 25,
                'niveau' => 'critical',
                'etat' => 'unread',
                'vue' => 'historique',
            ]));
    }

    public function test_alert_center_paginates_searches_and_marks_more_than_one_hundred_alerts(): void
    {
        $fixture = $this->createFixture();
        $oldestMessage = 'Archive exhaustive numéro 105';

        $rows = collect(range(1, 105))->map(function (int $index) use ($fixture): array {
            $createdAt = now()->subMinutes($index);

            return [
                'action_id' => $fixture['action']->id,
                'niveau' => 'warning',
                'type_evenement' => 'alerte_exhaustive_'.$index,
                'message' => 'Archive exhaustive numéro '.$index,
                'details' => json_encode(['resolved' => false], JSON_THROW_ON_ERROR),
                'lu' => false,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        });
        ActionLog::query()->insert($rows->all());

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.notifications.index', [
                'tab' => 'alertes',
                'type' => 'action_log',
                'per_page' => 15,
            ]))
            ->assertOk()
            ->assertViewHas('alertPaginator', function ($paginator): bool {
                return $paginator->total() === 105 && count($paginator->items()) === 15;
            });

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.notifications.index', [
                'tab' => 'alertes',
                'type' => 'action_log',
                'q' => 'archive exhaustive numero 105',
            ]))
            ->assertOk()
            ->assertSee($oldestMessage)
            ->assertViewHas('alertPaginator', fn ($paginator): bool => $paginator->total() === 1);

        $this->actingAs($fixture['admin'])
            ->post(route('workspace.alertes.read_all'))
            ->assertRedirect()
            ->assertSessionHas('success', 'Toutes les alertes actives ont été marquées comme lues.');

        $this->assertSame(105, $fixture['admin']->alertReads()
            ->where('source_type', 'action_log')
            ->count());
        $this->assertDatabaseHas('alert_reads', [
            'user_id' => $fixture['admin']->id,
            'message' => $oldestMessage,
        ]);
    }

    public function test_alert_center_neutralizes_array_query_values(): void
    {
        $fixture = $this->createFixture();
        $this->createAlert($fixture['action'], 'warning', 'Alerte accessible avec filtres invalides');

        $this->actingAs($fixture['admin'])
            ->get(route('workspace.notifications.index', [
                'tab' => 'alertes',
                'q' => ['invalide'],
                'niveau' => ['warning'],
                'etat' => ['unread'],
                'type' => ['action_log'],
                'vue' => ['historique'],
                'per_page' => [50],
                'page' => [2],
            ]))
            ->assertOk()
            ->assertViewHas('activeTab', 'alertes')
            ->assertViewHas('alertFilters', function (array $filters): bool {
                return $filters['q'] === ''
                    && $filters['niveau'] === null
                    && $filters['etat'] === null
                    && $filters['type'] === null
                    && $filters['vue'] === 'actives'
                    && $filters['per_page'] === 15;
            })
            ->assertSee('Alerte accessible avec filtres invalides');
    }

    /**
     * @return array{admin:User, action:Action}
     */
    private function createFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-ALERT',
            'libelle' => 'Direction alertes',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-ALERT',
            'libelle' => 'Service alertes',
            'actif' => true,
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'password_changed_at' => now(),
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS alertes',
            'periode_debut' => now()->year,
            'periode_fin' => now()->year + 2,
            'statut' => 'actif',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-ALERT',
            'libelle' => 'Axe alertes',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-ALERT',
            'libelle' => 'Objectif alertes',
            'date_echeance' => now()->addYears(2)->toDateString(),
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'annee' => now()->year,
            'titre' => 'PAO alertes',
            'objectif_operationnel' => 'Objectif operationnel alertes',
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $objectifOperationnel = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif operationnel alertes',
            'echeance' => now()->addYear()->toDateString(),
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOperationnel->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA alertes',
            'statut' => Pta::STATUS_EN_COURS,
        ]);
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOperationnel->id,
            'libelle' => 'Action alertes',
            'type_cible' => 'quantitative',
            'type_indicateur' => 'quantitatif',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'quantite_a_realiser' => 10,
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addMonth()->toDateString(),
            'date_echeance' => now()->addMonth()->toDateString(),
            'responsable_id' => $agent->id,
            'statut' => 'en_cours',
            'progression_reelle' => 30,
            'progression_theorique' => 60,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
        ]);

        return [
            'admin' => $admin,
            'action' => $action,
        ];
    }

    private function createAlert(Action $action, string $level, string $message): ActionLog
    {
        return ActionLog::query()->create([
            'action_id' => $action->id,
            'niveau' => $level,
            'type_evenement' => 'alerte_filtrage_'.$level,
            'message' => $message,
            'details' => ['resolved' => false],
            'lu' => false,
        ]);
    }
}
