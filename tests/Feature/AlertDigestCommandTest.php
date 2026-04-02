<?php

namespace Tests\Feature;

use App\Mail\AlertDigestMail;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Delegation;
use App\Models\Direction;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class AlertDigestCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_alert_digest_command_sends_emails_to_operational_profiles_with_alerts(): void
    {
        Mail::fake();
        $admin = $this->createAdminUser();

        $this->artisan('alertes:notifier --limit=10')
            ->assertSuccessful();

        Mail::assertSent(AlertDigestMail::class, function (AlertDigestMail $mail) use ($admin): bool {
            return $mail->hasTo($admin->email);
        });

        Mail::assertSent(AlertDigestMail::class, function (AlertDigestMail $mail): bool {
            return $mail->hasTo('ingrid@anbg.ga');
        });

        Mail::assertNotSent(AlertDigestMail::class, function (AlertDigestMail $mail): bool {
            return $mail->hasTo('loick.adan@anbg.ga');
        });
    }

    public function test_alert_digest_command_dry_run_does_not_send_emails(): void
    {
        Mail::fake();

        $this->artisan('alertes:notifier --dry-run')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_alert_digest_command_creates_database_notifications_for_profiles_with_alerts(): void
    {
        Mail::fake();
        $admin = $this->createAdminUser();

        $this->artisan('alertes:notifier --limit=10')
            ->assertSuccessful();

        $dg = User::query()->where('email', 'ingrid@anbg.ga')->firstOrFail();
        $cabinet = User::query()->where('email', 'loick.adan@anbg.ga')->firstOrFail();

        $adminNotification = $admin->notifications()->latest()->first();
        $dgNotification = $dg->notifications()->latest()->first();

        $this->assertNotNull($adminNotification);
        $this->assertNotNull($dgNotification);
        $this->assertSame('alertes', (string) ($adminNotification?->data['module'] ?? ''));
        $this->assertSame('alert_digest', (string) ($adminNotification?->data['meta']['event'] ?? ''));
        $this->assertSame('alertes', (string) ($dgNotification?->data['module'] ?? ''));
        $this->assertSame('alert_digest', (string) ($dgNotification?->data['meta']['event'] ?? ''));
        $this->assertSame(0, $cabinet->notifications()->count());
    }

    public function test_alert_digest_command_does_not_duplicate_daily_database_notification(): void
    {
        Mail::fake();
        $admin = $this->createAdminUser();

        $this->artisan('alertes:notifier --limit=10')
            ->assertSuccessful();

        $this->artisan('alertes:notifier --limit=10')
            ->assertSuccessful();

        $todayDigestCount = $admin->notifications()
            ->whereDate('created_at', now()->toDateString())
            ->get()
            ->filter(static function ($notification): bool {
                return (string) ($notification->data['module'] ?? '') === 'alertes'
                    && (string) ($notification->data['meta']['event'] ?? '') === 'alert_digest';
            })
            ->count();

        $this->assertSame(1, $todayDigestCount);
    }

    public function test_alert_digest_command_sends_digest_to_delegated_planning_reader_with_scoped_alerts(): void
    {
        Mail::fake();

        $fixture = $this->createDelegatedDigestFixture();

        $this->artisan('alertes:notifier --limit=10')
            ->assertSuccessful();

        Mail::assertSent(AlertDigestMail::class, function (AlertDigestMail $mail) use ($fixture): bool {
            return $mail->hasTo($fixture['delegue']->email);
        });
    }

    public function test_alert_digest_command_respects_escalation_chain_for_urgent_action_logs(): void
    {
        Mail::fake();

        $fixture = $this->createEscalationDigestFixture();

        $this->artisan('alertes:notifier --limit=10')
            ->assertSuccessful();

        foreach (['service', 'direction', 'planification', 'dg'] as $key) {
            Mail::assertSent(AlertDigestMail::class, function (AlertDigestMail $mail) use ($fixture, $key): bool {
                return $mail->hasTo($fixture[$key]->email);
            });
        }

        Mail::assertNotSent(AlertDigestMail::class, function (AlertDigestMail $mail) use ($fixture): bool {
            return $mail->hasTo($fixture['outsider']->email);
        });
    }

    /**
     * @return array{delegue: User}
     */
    private function createDelegatedDigestFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-DIG',
            'libelle' => 'Direction Digest',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-DIG',
            'libelle' => 'Service Digest',
            'actif' => true,
        ]);

        $delegant = User::factory()->create([
            'name' => 'Chef Digest',
            'email' => 'chef.digest@anbg.ga',
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'SRV-DIG-01',
            'agent_fonction' => 'Chef de service',
            'password_changed_at' => now(),
        ]);

        $delegue = User::factory()->create([
            'name' => 'Delegue Digest',
            'email' => 'delegue.digest@anbg.ga',
            'role' => User::ROLE_AGENT,
            'direction_id' => null,
            'service_id' => null,
            'agent_matricule' => 'AGT-DIG-01',
            'agent_fonction' => 'Agent delegue',
            'password_changed_at' => now(),
        ]);

        $responsable = User::factory()->create([
            'name' => 'Agent Digest',
            'email' => 'agent.digest@anbg.ga',
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'AGT-DIG-02',
            'agent_fonction' => 'Agent execution',
            'password_changed_at' => now(),
        ]);

        Delegation::query()->create([
            'delegant_id' => $delegant->id,
            'delegue_id' => $delegue->id,
            'role_scope' => Delegation::SCOPE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'permissions' => ['planning_read'],
            'motif' => 'Remplacement temporaire',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addDays(5),
            'statut' => 'active',
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS Digest',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-DIG',
            'libelle' => 'Axe Digest',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-DIG-1',
            'libelle' => 'Objectif Digest',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO Digest',
            'statut' => 'brouillon',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA Digest',
            'statut' => 'brouillon',
        ]);

        Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action Digest en retard',
            'description' => 'Action de test digest',
            'type_cible' => 'quantitative',
            'unite_cible' => 'taches',
            'quantite_cible' => 10,
            'date_debut' => now()->subDays(14)->toDateString(),
            'date_fin' => now()->subDays(7)->toDateString(),
            'date_echeance' => now()->subDays(7)->toDateString(),
            'frequence_execution' => 'hebdomadaire',
            'responsable_id' => $responsable->id,
            'statut' => 'non_demarre',
            'statut_dynamique' => 'en_retard',
            'progression_reelle' => 0,
            'progression_theorique' => 100,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
        ]);

        return [
            'delegue' => $delegue,
        ];
    }

    /**
     * @return array{service: User, direction: User, planification: User, dg: User, outsider: User}
     */
    private function createEscalationDigestFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-ESC',
            'libelle' => 'Direction Escalade',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-ESC',
            'libelle' => 'Service Escalade',
            'actif' => true,
        ]);

        $serviceUser = User::factory()->create([
            'name' => 'Service Escalade',
            'email' => 'service.escalade@anbg.ga',
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $directionUser = User::factory()->create([
            'name' => 'Direction Escalade',
            'email' => 'direction.escalade@anbg.ga',
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $planningUser = User::factory()->create([
            'name' => 'Planification Escalade',
            'email' => 'planification.escalade@anbg.ga',
            'role' => User::ROLE_PLANIFICATION,
            'direction_id' => null,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $dgUser = User::factory()->create([
            'name' => 'DG Escalade',
            'email' => 'dg.escalade@anbg.ga',
            'role' => User::ROLE_DG,
            'direction_id' => null,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $outsider = User::factory()->create([
            'name' => 'Service Hors Scope',
            'email' => 'outsider.escalade@anbg.ga',
            'role' => User::ROLE_SERVICE,
            'direction_id' => null,
            'service_id' => null,
            'password_changed_at' => now(),
        ]);

        $responsable = User::factory()->create([
            'name' => 'Agent Escalade',
            'email' => 'agent.escalade@anbg.ga',
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS Escalade',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-ESC',
            'libelle' => 'Axe Escalade',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OS-ESC-1',
            'libelle' => 'Objectif Escalade',
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO Escalade',
            'statut' => 'brouillon',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA Escalade',
            'statut' => 'brouillon',
        ]);

        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'libelle' => 'Action Escalade',
            'description' => 'Action ciblee pour digest',
            'type_cible' => 'qualitative',
            'resultat_attendu' => 'Livrer',
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addDays(10)->toDateString(),
            'date_echeance' => now()->addDays(10)->toDateString(),
            'frequence_execution' => 'hebdomadaire',
            'responsable_id' => $responsable->id,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'statut_validation' => 'soumise_chef',
            'progression_reelle' => 30,
            'progression_theorique' => 40,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'ressource_main_oeuvre' => true,
        ]);

        ActionLog::query()->create([
            'action_id' => $action->id,
            'action_week_id' => null,
            'niveau' => 'urgence',
            'type_evenement' => 'alerte_combinee_critique',
            'message' => 'Action en retard avec indicateur critique. Urgence et escalade DG requises.',
            'details' => ['kpi_global' => 35],
            'cible_role' => 'dg',
            'utilisateur_id' => null,
            'lu' => false,
        ]);

        return [
            'service' => $serviceUser,
            'direction' => $directionUser,
            'planification' => $planningUser,
            'dg' => $dgUser,
            'outsider' => $outsider,
        ];
    }
}
