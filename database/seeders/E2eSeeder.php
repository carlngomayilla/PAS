<?php

namespace Database\Seeders;

use App\Enums\MeetingStatus;
use App\Enums\MeetingType;
use App\Models\Direction;
use App\Models\Meeting;
use App\Models\MeetingPlan;
use App\Models\MeetingReport;
use App\Models\Service;
use App\Models\UniteDg;
use App\Models\User;
use App\Support\E2eEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class E2eSeeder extends Seeder
{
    public function run(): void
    {
        E2eEnvironment::assertSafe();
        $this->call([
            PlatformSettingsSeeder::class,
            RoleRegistrySeeder::class,
            RolePermissionSettingsSeeder::class,
            DashboardProfileSettingsSeeder::class,
            DynamicReferentialSettingsSeeder::class,
            DocumentPolicySettingsSeeder::class,
            ManagedKpiSettingsSeeder::class,
            WorkflowSettingsSeeder::class,
            ActionCalculationSettingsSeeder::class,
            ActionManagementSettingsSeeder::class,
            NotificationPolicySettingsSeeder::class,
        ]);

        $mainDirection = Direction::factory()->create(['code' => 'E2E-DIR', 'libelle' => 'Direction E2E principale']);
        $mainService = Service::factory()->create(['direction_id' => $mainDirection->id, 'code' => 'E2E-SRV', 'libelle' => 'Service E2E principal']);
        $outsideDirection = Direction::factory()->create(['code' => 'E2E-EXT', 'libelle' => 'Direction E2E externe']);
        $outsideService = Service::factory()->create(['direction_id' => $outsideDirection->id, 'code' => 'E2E-HORS', 'libelle' => 'Service E2E externe']);
        $generalDirection = Direction::factory()->create(['code' => 'E2E-DG', 'libelle' => 'Direction générale E2E']);

        $units = collect([
            UniteDg::CODE_SCIQ => ['Unité SCIQ E2E', true],
            UniteDg::CODE_DGA => ['Unité DGA E2E', true],
            UniteDg::CODE_CABINET => ['Unité Cabinet E2E', true],
            UniteDg::CODE_UCAS => ['Unité UCAS E2E', false],
        ])->mapWithKeys(fn (array $definition, string $code): array => [
            $code => UniteDg::query()->create([
                'direction_id' => $generalDirection->id,
                'code' => $code,
                'libelle' => $definition[0],
                'portee_globale' => $definition[1],
                'actif' => true,
            ]),
        ]);

        $password = Hash::make((string) config('e2e.password'));
        $globalProfiles = [
            ['Super administrateur E2E', 'super.admin.e2e@example.test', User::ROLE_SUPER_ADMIN, null],
            ['Administrateur fonctionnel E2E', 'admin.fonctionnel.e2e@example.test', User::ROLE_ADMIN_FONCTIONNEL, null],
            ['Auditeur E2E', 'auditeur.e2e@example.test', User::ROLE_AUDITEUR, null],
            ['Directeur général E2E', 'dg.e2e@example.test', User::ROLE_DG, null],
            ['Planification E2E', 'planification.e2e@example.test', User::ROLE_PLANIFICATION, null],
            ['Chef planification E2E', 'chef.planification.e2e@example.test', User::ROLE_CHEF_PLANIFICATION, null],
            ['Cabinet E2E', 'cabinet.e2e@example.test', User::ROLE_CABINET, UniteDg::CODE_CABINET],
            ['Chef unité Cabinet E2E', 'chef.unite.cabinet.e2e@example.test', User::ROLE_CHEF_UNITE_CABINET, UniteDg::CODE_CABINET],
            ['Supervision DGA E2E', 'dga.supervision.e2e@example.test', User::ROLE_DGA_SUPERVISION, UniteDg::CODE_DGA],
            ['Chef unité DGA E2E', 'chef.unite.dga.e2e@example.test', User::ROLE_CHEF_UNITE_DGA, UniteDg::CODE_DGA],
            ['Chef unité UCAS E2E', 'chef.unite.ucas.e2e@example.test', User::ROLE_CHEF_UNITE_UCAS, UniteDg::CODE_UCAS],
            ['UCAS E2E', 'ucas.e2e@example.test', User::ROLE_UCAS, UniteDg::CODE_UCAS],
            ['Responsable SCIQ E2E', 'sciq.e2e@example.test', User::ROLE_SCIQ, UniteDg::CODE_SCIQ],
            ['Chef unité SCIQ E2E', 'chef.unite.sciq.e2e@example.test', User::ROLE_CHEF_UNITE_SCIQ, UniteDg::CODE_SCIQ],
        ];

        foreach ($globalProfiles as [$name, $email, $role, $unitCode]) {
            $this->createUser($name, $email, $role, $password, $unitCode ? $generalDirection : null, null, $unitCode ? $units->get($unitCode) : null);
        }

        $chief = $this->createUser('Chef de service E2E', 'chef.service.e2e@example.test', User::ROLE_SERVICE, $password, $mainDirection, $mainService);
        $director = $this->createUser('Directeur E2E', 'directeur.e2e@example.test', User::ROLE_DIRECTION, $password, $mainDirection);
        $this->createUser('Agent E2E', 'agent.e2e@example.test', User::ROLE_AGENT, $password, $mainDirection, $mainService);
        $this->createUser('Agent autre service E2E', 'agent.autre.service.e2e@example.test', User::ROLE_AGENT, $password, $outsideDirection, $outsideService);
        $this->createUser('Compte inactif E2E', 'inactif.e2e@example.test', User::ROLE_AGENT, $password, $mainDirection, $mainService, null, false);

        foreach (['chromium', 'firefox', 'mobile-chrome'] as $project) {
            foreach (['cycle', 'correction', 'téléversement'] as $kind) {
                $this->createElapsedMeeting("Réunion E2E {$kind} {$project}", $mainDirection, $mainService, $chief, $director);
            }
        }

        $protectedMeeting = $this->createElapsedMeeting('Réunion E2E protégée autre service', $mainDirection, $mainService, $chief, $director, MeetingStatus::EnValidationSciq);
        $protectedFile = $this->pdfFixture('PV protégé E2E');
        $protectedPath = 'e2e/fixtures/pv-protege.pdf';
        Storage::disk('local')->put($protectedPath, $protectedFile);
        MeetingReport::factory()->create([
            'meeting_id' => $protectedMeeting->id,
            'file_path' => $protectedPath,
            'original_file_name' => 'pv-protege-e2e.pdf',
            'file_size' => strlen($protectedFile),
            'mime_type' => 'application/pdf',
            'checksum' => hash('sha256', $protectedFile),
            'is_encrypted' => false,
            'status' => MeetingStatus::EnValidationSciq,
            'summary' => 'Synthèse E2E protégée réservée au périmètre principal.',
            'uploaded_by' => $chief->id,
            'uploaded_at' => now()->subHour(),
        ]);

        Meeting::factory()->create([
            'direction_id' => $mainDirection->id,
            'service_id' => $mainService->id,
            'meeting_type' => MeetingType::Service,
            'label' => 'Réunion E2E interface',
            'participant_ids' => [$chief->id, $director->id],
            'original_scheduled_date' => today()->addDay(),
            'current_scheduled_date' => today()->addDay(),
            'scheduled_time' => '10:00',
            'year' => today()->addDay()->year,
            'quarter' => MeetingPlan::quarterForMonth(today()->addDay()->month),
            'month' => today()->addDay()->month,
            'created_by' => $chief->id,
            'responsible_id' => $chief->id,
        ]);
    }

    private function createUser(string $name, string $email, string $role, string $password, ?Direction $direction = null, ?Service $service = null, ?UniteDg $unit = null, bool $isActive = true): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'direction_id' => $direction?->id,
            'service_id' => $service?->id,
            'unite_dg_id' => $unit?->id,
            'is_active' => $isActive,
            'password_changed_at' => now(),
        ]);
    }

    private function createElapsedMeeting(string $label, Direction $direction, Service $service, User $chief, User $director, MeetingStatus $status = MeetingStatus::PvAttendu): Meeting
    {
        $scheduledDate = today()->subDay();

        return Meeting::factory()->create([
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'meeting_type' => MeetingType::Service,
            'label' => $label,
            'location' => 'Salle de réunion E2E',
            'agenda' => 'Ordre du jour réservé aux tests navigateur.',
            'participant_ids' => [$chief->id, $director->id],
            'original_scheduled_date' => $scheduledDate,
            'current_scheduled_date' => $scheduledDate,
            'scheduled_time' => '09:00',
            'held_at' => $scheduledDate->copy()->setTime(10, 0),
            'year' => $scheduledDate->year,
            'quarter' => MeetingPlan::quarterForMonth($scheduledDate->month),
            'month' => $scheduledDate->month,
            'status' => $status,
            'created_by' => $chief->id,
            'responsible_id' => $chief->id,
        ]);
    }

    private function pdfFixture(string $title): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Title ({$title}) >>\nendobj\n%%EOF\n";
    }
}
