<?php

namespace Tests\Concerns;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\UploadedFile;

trait CreatesAiPtaFixtures
{
    /**
     * @return array{direction:Direction,service:Service,exercice:Exercice}
     */
    protected function createAiReferential(): array
    {
        $direction = Direction::query()->firstOrCreate([
            'code' => 'DSI',
        ], [
            'libelle' => 'Direction SI',
            'actif' => true,
        ]);

        $service = Service::query()->firstOrCreate([
            'direction_id' => $direction->id,
            'code' => 'APP',
        ], [
            'libelle' => 'Service Applications',
            'actif' => true,
        ]);

        $exercice = Exercice::query()->firstOrCreate([
            'annee' => 2026,
        ], [
            'libelle' => 'Exercice 2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'statut' => Exercice::STATUT_OUVERT,
            'is_active' => true,
        ]);

        return compact('direction', 'service', 'exercice');
    }

    protected function createAiUser(string $role = User::ROLE_PLANIFICATION, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'is_active' => true,
            'password_changed_at' => now(),
        ], $overrides));
    }

    protected function validPtaCsv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('pta.csv', implode("\n", [
            'exercice;libelle action;direction;service;date fin;budget previsionnel;statut initial;indicateur;cible',
            '2026;Action PTA IA;Direction SI;Service Applications;2026-12-31;1500;non_demarre;Taux de livraison;100',
        ]));
    }

    protected function invalidPtaCsv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('pta.csv', implode("\n", [
            'exercice;libelle action;direction;service;date fin;budget previsionnel',
            '2026;Action a corriger;Direction SI;;2026-12-31;1500',
        ]));
    }

    /**
     * @return array{direction:Direction,service:Service,exercice:Exercice,pas:Pas,pao:Pao,pta:Pta,action:Action}
     */
    protected function createReportFixture(): array
    {
        $base = $this->createAiReferential();

        $pas = Pas::query()->create([
            'exercice_id' => $base['exercice']->id,
            'titre' => 'PAS 2026',
            'periode_debut' => 2026,
            'periode_fin' => 2026,
        ]);

        $pao = Pao::query()->create([
            'exercice_id' => $base['exercice']->id,
            'pas_id' => $pas->id,
            'direction_id' => $base['direction']->id,
            'annee' => 2026,
            'titre' => 'PAO 2026',
        ]);

        $pta = Pta::query()->create([
            'exercice_id' => $base['exercice']->id,
            'pao_id' => $pao->id,
            'direction_id' => $base['direction']->id,
            'service_id' => $base['service']->id,
            'titre' => 'PTA 2026',
        ]);

        $action = Action::query()->create([
            'exercice_id' => $base['exercice']->id,
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'code' => 'ACT-IA-001',
            'libelle' => 'Action de test rapport IA',
            'date_fin' => '2026-12-31',
            'statut' => 'en_cours',
            'contexte_action' => Action::CONTEXT_OPERATIONNEL,
            'origine_action' => Action::ORIGIN_PTA,
            'progression_reelle' => 25,
            'montant_estime' => 1500,
        ]);

        return $base + compact('pas', 'pao', 'pta', 'action');
    }
}
