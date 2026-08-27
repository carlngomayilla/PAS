<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\Actions\ActionTrackingService;
use App\Services\AdminLayoutViewDataService;
use App\Services\Alerting\AlertCenterService;
use App\Services\Alerting\AlertReadService;
use App\Services\Analytics\AnalyticsCacheVersionService;
use App\Services\DeadlineExtensionQueueService;
use App\Services\ExerciceContext;
use App\Services\PersonalTaskService;
use App\Services\PlanningModificationLockService;
use App\Services\RolePermissionSettings;
use App\Services\RoleRegistryService;
use App\Services\UserWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminLayoutViewDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_payload_is_safe_and_preserves_an_explicit_period_label(): void
    {
        $this->mock(ExerciceContext::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('activeLabel');
        });

        $payload = app(AdminLayoutViewDataService::class)->data(null, [
            'headerActivePeriodLabel' => 'Période fournie',
        ]);

        $this->assertNull($payload['layoutUser']);
        $this->assertSame('Période fournie', $payload['headerActivePeriodLabel']);
        $this->assertSame([], $payload['headerSidebarBadges']);
        $this->assertSame([], $payload['layoutWorkspaceModules']);
        $this->assertSame('none', $payload['headerBellBadgeKind']);
        $this->assertSame('Accès limité', $payload['navbarScopeLabel']);
        $this->assertTrue($payload['headerNotifications']->isEmpty());
    }

    public function test_authenticated_payload_reuses_controller_data_and_builds_header_badges(): void
    {
        Cache::flush();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'direction_id' => null,
            'service_id' => null,
        ]);
        $now = now();

        DB::table('notifications')->insert([
            [
                'id' => (string) Str::uuid(),
                'type' => self::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'module' => 'actions',
                    'title' => 'Action à traiter',
                    'message' => 'Une action attend votre intervention.',
                ], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'type' => self::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'module' => 'alertes',
                    'title' => 'Alerte technique',
                    'message' => 'Cette notification est remplacée par le compteur d’alertes.',
                ], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => $now->copy()->subSecond(),
                'updated_at' => $now->copy()->subSecond(),
            ],
        ]);

        $this->mock(RolePermissionSettings::class, function (MockInterface $mock): void {
            $mock->shouldReceive('has')->andReturnTrue();
        });
        $this->mock(ExerciceContext::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('activeLabel');
        });
        $this->mock(AlertReadService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('readFingerprintsForUser')
                ->once()
                ->with($user)
                ->andReturn([]);
        });
        $this->mock(AlertCenterService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('summaryForUser')
                ->once()
                ->with($user, [])
                ->andReturn([
                    'total' => 3,
                    'unread' => 2,
                    'urgence' => 0,
                    'critical' => 1,
                    'warning' => 1,
                    'info' => 1,
                ]);
        });
        $this->mock(AnalyticsCacheVersionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dashboardVersion')->once()->andReturn(7);
        });
        $this->mock(PlanningModificationLockService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('canGivePlanifAvis')->once()->with($user)->andReturnTrue();
        });
        $this->mock(PersonalTaskService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('openTaskCount')->once()->with($user)->andReturn(4);
            $mock->shouldReceive('controlTaskCount')->once()->with($user)->andReturn(2);
        });
        $this->mock(DeadlineExtensionQueueService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('actionableCount')->once()->with($user)->andReturn(3);
        });
        $this->mock(UserWorkspaceService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('modulesFor');
        });
        $this->mock(AccessScopeService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('scopeFor');
        });
        $this->mock(RoleRegistryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('label')->once()->with(User::ROLE_SUPER_ADMIN)->andReturn('Super Admin');
        });

        $providedModules = [[
            'code' => 'pilotage',
            'label' => 'Pilotage fourni',
            'endpoint' => '/dashboard',
        ]];
        $layoutNotifications = $user->notifications()->latest()->get();
        $payload = app(AdminLayoutViewDataService::class)->data($user, [
            'headerActivePeriodLabel' => 'Ex. 2031',
            'layoutNotifications' => $layoutNotifications,
            'layoutWorkspaceModules' => $providedModules,
            'accessScope' => [
                'scope_type' => AccessScopeService::TYPE_GLOBAL,
            ],
        ]);

        $this->assertSame($user->id, $payload['layoutUser']->id);
        $this->assertSame('Ex. 2031', $payload['headerActivePeriodLabel']);
        $this->assertSame($providedModules, $payload['layoutWorkspaceModules']);
        $this->assertSame('Super Admin', $payload['layoutUserRoleLabel']);
        $this->assertSame('Vue globale', $payload['navbarScopeLabel']);
        $this->assertSame(2, $payload['headerUnreadCount']);
        $this->assertSame(1, $payload['headerNotificationUnreadCount']);
        $this->assertSame(1, $payload['headerNotifications']->count());
        $this->assertSame('actions', $payload['headerNotifications']->first()->data['module']);
        $this->assertSame(3, $payload['headerSidebarBadges']['notifications']);
        $this->assertSame(1, $payload['headerSidebarBadges']['actions']);
        $this->assertSame(4, $payload['headerSidebarBadges']['mes_taches']);
        $this->assertSame(2, $payload['headerSidebarBadges']['controle']);
        $this->assertSame(3, $payload['headerSidebarBadges']['reports_echeance']);
        $this->assertSame('both', $payload['headerBellBadgeKind']);
        $this->assertSame(3, $payload['headerBellUnreadCount']);
        $this->assertTrue($payload['sidebarIsSuperAdmin']);
        $this->assertFalse($payload['sidebarIsDafFinanceReviewer']);
    }

    public function test_validation_badge_keeps_the_action_review_workflow_count(): void
    {
        Cache::flush();
        $direction = Direction::factory()->create();
        $service = Service::factory()->create([
            'direction_id' => $direction->id,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'direction_id' => null,
            'service_id' => null,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS badge layout',
            'periode_debut' => 2031,
            'periode_fin' => 2033,
            'statut' => 'actif',
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'annee' => 2031,
            'titre' => 'PAO badge layout',
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA badge layout',
            'statut' => Pta::STATUS_EN_COURS,
        ]);
        DB::table('actions')->insert([
            'pta_id' => $pta->id,
            'libelle' => 'Action soumise au contrôle',
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CONTROLE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(ExerciceContext::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('activeLabel');
        });
        $this->mock(AlertReadService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('readFingerprintsForUser')->andReturn([]);
        });
        $this->mock(AlertCenterService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('summaryForUser')->andReturn([
                'total' => 0,
                'unread' => 0,
                'urgence' => 0,
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
            ]);
        });
        $this->mock(AnalyticsCacheVersionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dashboardVersion')->once()->andReturn(43);
        });
        $this->mock(PlanningModificationLockService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canGivePlanifAvis')->once()->andReturnTrue();
        });
        $this->mock(PersonalTaskService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('openTaskCount')->once()->andReturn(0);
            $mock->shouldReceive('controlTaskCount')->once()->andReturn(0);
        });
        $this->mock(DeadlineExtensionQueueService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('actionableCount')->once()->andReturn(0);
        });
        $this->mock(UserWorkspaceService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('modulesFor');
        });
        $this->mock(AccessScopeService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('scopeFor');
        });
        $this->mock(RolePermissionSettings::class, function (MockInterface $mock): void {
            $mock->shouldReceive('has')->andReturnTrue();
        });
        $this->mock(RoleRegistryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('label')->once()->andReturn('Planification');
        });

        $payload = app(AdminLayoutViewDataService::class)->data($user, [
            'headerActivePeriodLabel' => 'Ex. 2031',
            'layoutNotifications' => collect(),
            'layoutWorkspaceModules' => [],
            'accessScope' => [
                'scope_type' => AccessScopeService::TYPE_GLOBAL,
            ],
        ]);

        $this->assertSame(1, $payload['validationBadgeCount']);
        $this->assertSame(1, $payload['headerSidebarBadges']['actions']);
    }

    public function test_admin_layout_and_sidebar_are_passive_business_views(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/admin.blade.php'));
        $sidebar = (string) file_get_contents(resource_path('views/components/admin/sidebar.blade.php'));

        foreach ([
            'app(',
            '::query(',
            'notifications(',
            'unreadNotifications(',
            'workspaceModules(',
            'accessScope(',
            'roleLabel(',
            'auth()->user',
        ] as $forbiddenCall) {
            $this->assertStringNotContainsString($forbiddenCall, $layout);
            $this->assertStringNotContainsString($forbiddenCall, $sidebar);
        }

        $this->assertStringContainsString(
            "View::composer('layouts.admin', AdminLayoutViewDataService::class)",
            (string) file_get_contents(app_path('Providers/AppServiceProvider.php')),
        );
        $this->assertStringContainsString(
            "'direction:id,code,libelle'",
            (string) file_get_contents(app_path('Services/AdminLayoutViewDataService.php')),
        );
    }
}
