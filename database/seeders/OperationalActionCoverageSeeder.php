<?php

namespace Database\Seeders;

use App\Models\Action;
use App\Models\ActionKpi;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OperationalActionCoverageSeeder extends Seeder
{
    public function run(): void
    {
        Model::unguarded(function (): void {
            $year = (int) now()->year;
            $exercice = $this->ensureExercice($year);
            $directions = $this->ensureFourDirections();

            $pas = Pas::query()->firstOrCreate(
                ['titre' => "PAS ANBG {$year} - couverture operationnelle"],
                [
                    'exercice_id' => $exercice->id,
                    'periode_debut' => $year,
                    'periode_fin' => $year,
                ]
            );
            $pas->forceFill([
                'exercice_id' => $exercice->id,
                'statut' => 'valide',
                'valide_le' => now(),
            ])->save();
            $pas->directions()->syncWithoutDetaching($directions->pluck('id')->all());

            $servicesCovered = 0;
            $actionsTouched = 0;

            foreach ($directions as $direction) {
                $services = $this->servicesForDirection($direction);
                [$axis, $strategicObjective] = $this->ensurePasStructure($pas, $direction);

                foreach ($services as $service) {
                    $users = $this->activeServiceUsers($service);
                    if ($users->isEmpty()) {
                        $users = collect([$this->ensureServiceAgent($service)]);
                    }

                    $pao = $this->ensurePao($pas, $strategicObjective, $direction, $service, $exercice, $year);
                    $objectif = $this->ensureOperationalObjective($pas, $axis, $strategicObjective, $pao, $direction, $service, $year);
                    $pta = $this->ensurePta($pao, $objectif, $direction, $service, $exercice);

                    foreach ($users as $user) {
                        $this->ensureServiceActions($pta, $pao, $objectif, $service, $user, $exercice, $year);
                        $actionsTouched += 2;
                    }

                    $servicesCovered++;
                }
            }

            $this->command?->info("Couverture operationnelle OK: {$actionsTouched} actions attribuees sur {$servicesCovered} services / 4 directions.");
        });
    }

    private function ensureExercice(int $year): Exercice
    {
        return Exercice::query()->updateOrCreate(
            ['annee' => $year],
            [
                'libelle' => "Exercice {$year}",
                'date_debut' => "{$year}-01-01",
                'date_fin' => "{$year}-12-31",
                'statut' => Exercice::STATUT_OUVERT,
                'is_active' => true,
            ]
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, Direction>
     */
    private function ensureFourDirections()
    {
        $directions = Direction::query()
            ->where('actif', true)
            ->orderBy('id')
            ->take(4)
            ->get();

        for ($i = $directions->count() + 1; $i <= 4; $i++) {
            $directions->push(Direction::query()->firstOrCreate(
                ['code' => 'COVD'.$i],
                ['libelle' => 'Direction de couverture '.$i, 'actif' => true]
            ));
        }

        return $directions->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Service>
     */
    private function servicesForDirection(Direction $direction)
    {
        $services = Service::query()
            ->where('direction_id', (int) $direction->id)
            ->where('actif', true)
            ->orderBy('id')
            ->get();

        if ($services->isNotEmpty()) {
            return $services;
        }

        for ($i = 1; $i <= 2; $i++) {
            $services->push(Service::query()->firstOrCreate(
                [
                    'direction_id' => (int) $direction->id,
                    'code' => $this->code('COVS', (string) $direction->code, $i),
                ],
                [
                    'libelle' => 'Service de couverture '.$i,
                    'type' => 'operationnel',
                    'actif' => true,
                    'is_operational' => true,
                ]
            ));
        }

        return $services;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function activeServiceUsers(Service $service)
    {
        return User::query()
            ->where('service_id', (int) $service->id)
            ->where('is_active', true)
            ->orderByRaw(
                "CASE WHEN role = ? THEN 0 WHEN role = ? THEN 1 ELSE 2 END",
                [User::ROLE_AGENT, User::ROLE_SERVICE]
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{0: PasAxe, 1: PasObjectif}
     */
    private function ensurePasStructure(Pas $pas, Direction $direction): array
    {
        $axis = PasAxe::query()->updateOrCreate(
            ['pas_id' => (int) $pas->id, 'code' => $this->code('AX', (string) $direction->code, (int) $direction->id)],
            [
                'direction_id' => (int) $direction->id,
                'libelle' => 'Axe de suivi operationnel - '.$direction->libelle,
                'description' => 'Axe technique pour verifier la couverture des services.',
                'ordre' => (int) $direction->id,
            ]
        );

        $objective = PasObjectif::query()->updateOrCreate(
            ['pas_axe_id' => (int) $axis->id, 'code' => 'OBJ-COV'],
            [
                'libelle' => 'Verifier le suivi operationnel des actions',
                'description' => 'Objectif de controle pour les actions, financements et statistiques.',
                'ordre' => 1,
                'indicateur_global' => 'Taux de couverture des actions par service',
                'valeur_cible' => 100,
            ]
        );

        return [$axis, $objective];
    }

    private function ensureServiceAgent(Service $service): User
    {
        $email = 'agent.service.'.$service->id.'@anbg.test';
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => 'Agent '.$service->code,
            'password' => $user->exists ? $user->password : Hash::make('password'),
            'password_changed_at' => now(),
            'email_verified_at' => now(),
            'role' => User::ROLE_AGENT,
            'custom_role_code' => null,
            'direction_id' => (int) $service->direction_id,
            'service_id' => (int) $service->id,
            'is_agent' => true,
            'is_active' => true,
            'agent_matricule' => 'COV-'.$service->id,
            'agent_fonction' => 'Agent de suivi operationnel',
        ])->save();

        return $user;
    }

    private function ensurePao(Pas $pas, PasObjectif $objective, Direction $direction, Service $service, Exercice $exercice, int $year): Pao
    {
        $pao = Pao::query()->firstOrNew([
            'pas_objectif_id' => (int) $objective->id,
            'annee' => $year,
            'direction_id' => (int) $direction->id,
            'service_id' => (int) $service->id,
        ]);

        $pao->fill([
            'exercice_id' => (int) $exercice->id,
            'pas_id' => (int) $pas->id,
            'titre' => 'PAO '.$year.' - '.$service->code,
            'echeance' => "{$year}-12-31",
            'objectif_operationnel' => 'Assurer le suivi operationnel des actions du service '.$service->code,
            'resultats_attendus' => 'Deux actions de verification sont assignees et mesurables.',
            'indicateurs_associes' => 'Actions creees, financement renseigne, statistiques coherentes.',
        ]);
        $pao->forceFill([
            'statut' => Pao::STATUS_VALIDE,
            'valide_le' => now(),
        ])->save();

        return $pao;
    }

    private function ensureOperationalObjective(
        Pas $pas,
        PasAxe $axis,
        PasObjectif $strategicObjective,
        Pao $pao,
        Direction $direction,
        Service $service,
        int $year
    ): ObjectifOperationnel {
        return ObjectifOperationnel::query()->updateOrCreate(
            ['pao_id' => (int) $pao->id, 'service_id' => (int) $service->id],
            [
                'pas_id' => (int) $pas->id,
                'pas_axe_id' => (int) $axis->id,
                'pas_objectif_id' => (int) $strategicObjective->id,
                'direction_id' => (int) $direction->id,
                'libelle' => 'Objectif operationnel '.$service->code,
                'description' => 'Objectif genere pour verifier le cycle creation-suivi-validation-statistiques.',
                'echeance' => "{$year}-12-31",
                'indicateurs' => 'Progression brute, progression officielle, statut validation.',
                'statut' => Pao::STATUS_VALIDE,
            ]
        );
    }

    private function ensurePta(Pao $pao, ObjectifOperationnel $objectif, Direction $direction, Service $service, Exercice $exercice): Pta
    {
        $pta = Pta::query()->firstOrNew([
            'pao_id' => (int) $pao->id,
            'service_id' => (int) $service->id,
        ]);

        $pta->fill([
            'exercice_id' => (int) $exercice->id,
            'objectif_operationnel_id' => (int) $objectif->id,
            'direction_id' => (int) $direction->id,
            'titre' => 'PTA - '.$service->code,
            'description' => 'PTA de verification operationnelle avec deux actions par service.',
        ]);
        $pta->forceFill([
            'statut' => Pta::STATUS_VALIDE,
            'valide_le' => now(),
        ])->save();

        return $pta;
    }

    private function ensureServiceActions(Pta $pta, Pao $pao, ObjectifOperationnel $objectif, Service $service, User $agent, Exercice $exercice, int $year): void
    {
        $suffix = $this->actionUserSuffix($agent);

        $this->saveAction(
            $pta,
            $pao,
            $objectif,
            $agent,
            $exercice,
            [
                'libelle' => 'Action suivi operationnel - '.$service->code.' - '.$suffix,
                'description' => 'Action de suivi en cours attribuee a '.$agent->name.' pour verifier les statistiques brutes.',
                'date_debut' => "{$year}-01-15",
                'date_fin' => "{$year}-11-30",
                'financement_requis' => false,
                'progression' => 45,
                'statut' => 'en_cours',
                'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
                'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                'kpi_delai' => 80,
                'kpi_performance' => 45,
                'kpi_conformite' => 0,
            ]
        );

        $this->saveAction(
            $pta,
            $pao,
            $objectif,
            $agent,
            $exercice,
            [
                'libelle' => 'Action financee validee - '.$service->code.' - '.$suffix,
                'description' => 'Action financee attribuee a '.$agent->name.' pour verifier la sauvegarde du champ nature_financement.',
                'date_debut' => "{$year}-02-01",
                'date_fin' => "{$year}-10-31",
                'financement_requis' => true,
                'montant_estime' => 500000,
                'nature_financement' => 'Fonctionnement',
                'description_financement' => 'Financement requis pour les activites operationnelles du service.',
                'source_financement' => 'Budget ANBG',
                'commentaire_financement' => 'Dossier de verification operationnelle.',
                'progression' => 100,
                'statut' => 'termine',
                'statut_dynamique' => ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
                'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
                'financement_statut' => Action::FINANCEMENT_ACCORDE_DG,
                'kpi_delai' => 100,
                'kpi_performance' => 100,
                'kpi_conformite' => 95,
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function saveAction(Pta $pta, Pao $pao, ObjectifOperationnel $objectif, User $agent, Exercice $exercice, array $payload): void
    {
        $action = Action::query()->firstOrNew([
            'pta_id' => (int) $pta->id,
            'libelle' => (string) $payload['libelle'],
        ]);

        $progression = (float) $payload['progression'];
        $globalKpi = round(((float) $payload['kpi_delai'] + (float) $payload['kpi_performance'] + (float) $payload['kpi_conformite']) / 3, 2);

        $action->fill([
            'exercice_id' => (int) $exercice->id,
            'pao_id' => (int) $pao->id,
            'objectif_operationnel_id' => (int) $objectif->id,
            'responsable_id' => (int) $agent->id,
            'mode_evaluation' => Action::MODE_QUANTITATIF,
            'description' => $payload['description'],
            'type_cible' => 'quantitative',
            'unite_cible' => '%',
            'quantite_cible' => 100,
            'seuil_minimum' => 80,
            'seuil_mode' => 'unique',
            'date_debut' => $payload['date_debut'],
            'date_fin' => $payload['date_fin'],
            'date_echeance' => $payload['date_fin'],
            'frequence_execution' => ActionTrackingService::FREQUENCE_MENSUELLE,
            'financement_requis' => (bool) $payload['financement_requis'],
            'montant_estime' => $payload['montant_estime'] ?? null,
            'nature_financement' => $payload['nature_financement'] ?? null,
            'description_financement' => $payload['description_financement'] ?? null,
            'source_financement' => $payload['source_financement'] ?? null,
            'commentaire_financement' => $payload['commentaire_financement'] ?? null,
        ]);
        $action->forceFill([
            'statut' => $payload['statut'],
            'statut_dynamique' => $payload['statut_dynamique'],
            'statut_validation' => $payload['statut_validation'],
            'progression_reelle' => $progression,
            'progression_theorique' => $progression,
            'quantite_realisee' => $progression,
            'taux_atteinte_cible' => $progression,
            'taux_global' => $progression,
            'financement_statut' => $payload['financement_statut']
                ?? ((bool) $payload['financement_requis'] ? Action::FINANCEMENT_A_TRAITER_DAF : Action::FINANCEMENT_NON_REQUIS),
            'financement_soumis_le' => (bool) $payload['financement_requis'] ? now() : null,
            'financement_notifie_le' => (bool) $payload['financement_requis'] ? now() : null,
            'soumise_le' => now(),
            'evalue_le' => now(),
            'direction_valide_le' => $payload['statut_validation'] === ActionTrackingService::VALIDATION_VALIDEE_DIRECTION ? now() : null,
            'taux_valide_chef' => $payload['statut_validation'] === ActionTrackingService::VALIDATION_VALIDEE_DIRECTION ? $progression : null,
        ])->save();

        $action->responsables()->syncWithoutDetaching([
            (int) $agent->id => ['is_primary' => true],
        ]);

        ActionKpi::query()->updateOrCreate(
            ['action_id' => (int) $action->id],
            [
                'kpi_delai' => $payload['kpi_delai'],
                'kpi_performance' => $payload['kpi_performance'],
                'kpi_conformite' => $payload['kpi_conformite'],
                'kpi_global' => $globalKpi,
                'progression_reelle' => $progression,
                'progression_theorique' => $progression,
                'statut_calcule' => $payload['statut_dynamique'],
                'derniere_evaluation_at' => now(),
            ]
        );
    }

    private function code(string $prefix, string $seed, int $suffix): string
    {
        $clean = Str::of($seed)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '')->limit(12, '');

        return $prefix.$clean.$suffix;
    }

    private function actionUserSuffix(User $user): string
    {
        $candidate = trim((string) ($user->agent_matricule ?: Str::of((string) $user->email)->before('@')));

        if ($candidate === '') {
            $candidate = 'USER-'.$user->id;
        }

        return (string) Str::of($candidate)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->trim('-')
            ->limit(32, '');
    }
}
