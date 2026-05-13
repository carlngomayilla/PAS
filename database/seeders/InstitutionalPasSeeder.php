<?php

namespace Database\Seeders;

use App\Models\Action;
use App\Models\Delegation;
use App\Models\User;
use App\Models\Exercice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstitutionalPasSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $actorId = $this->actorId();

        $this->seedExercises($now);

        $directionIds = DB::table('directions')
            ->where('actif', true)
            ->orderBy('code')
            ->pluck('id', 'code')
            ->mapWithKeys(static fn ($id, $code): array => [(string) $code => (int) $id])
            ->all();

        foreach ($this->pasItems() as $pasData) {
            $pasId = $this->upsertPas($pasData, $actorId, $now);
            $this->syncPasDirections($pasId, array_values($directionIds), $now);
            $this->syncAxes($pasId, $pasData, $actorId, $now);

            if ((int) $pasData['periode_debut'] === 2020 && (int) $pasData['periode_fin'] === 2025) {
                $this->seedCompletedLegacyActions($pasId, $actorId, $now);
            }
        }

        $this->seedCurrentWorkflowSamples($now);
        $this->syncObjectifsOperationnels($now);
        $this->seedCurrentUserActionCoverage($actorId, $now);
        $this->syncObjectifsOperationnels($now);
        $this->seedTaskDelegations($actorId, $now);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pasItems(): array
    {
        return [
            [
                'titre' => 'PAS ANBG 2020-2025',
                'periode_debut' => 2020,
                'periode_fin' => 2025,
                'statut' => 'verrouille',
                'axes' => [
                    [
                        'code' => 'AXE-I',
                        'libelle' => 'GOUVERNANCE INSTITUTIONNELLE ET QUALITÉ DE SERVICE',
                        'objectifs' => [
                            'Renforcer la gouvernance de l’agence',
                            'Améliorer la qualité du service aux usagers',
                        ],
                    ],
                    [
                        'code' => 'AXE-II',
                        'libelle' => 'GESTION OPTIMISÉE DES BOURSES',
                        'objectifs' => [
                            'Optimiser l’attribution et le suivi des bourses',
                            'Maîtriser les délais de traitement des dossiers',
                        ],
                    ],
                    [
                        'code' => 'AXE-III',
                        'libelle' => 'DIGITALISATION ET FIABILISATION DES DONNÉES',
                        'objectifs' => [
                            'Moderniser les outils d’information',
                            'Sécuriser et fiabiliser les données des boursiers',
                        ],
                    ],
                    [
                        'code' => 'AXE-IV',
                        'libelle' => 'PARTENARIATS ET SOUTENABILITÉ FINANCIÈRE',
                        'objectifs' => [
                            'Développer les partenariats institutionnels',
                            'Renforcer la soutenabilité financière du dispositif',
                        ],
                    ],
                ],
            ],
            [
                'titre' => 'PAS ANBG 2026-2028',
                'periode_debut' => 2026,
                'periode_fin' => 2028,
                'statut' => 'valide',
                'axes' => [
                    [
                        'code' => 'AXE-I',
                        'libelle' => 'ÉLABORATION D’UNE OFFRE DE BOURSE ADAPTÉE',
                        'objectifs' => [
                            'Mettre en place des programmes de formation ciblés',
                            'Nouer des partenariats pour favoriser l’insertion professionnelle des étudiants boursiers',
                        ],
                    ],
                    [
                        'code' => 'AXE-II',
                        'libelle' => 'REDRESSEMENT DE LA SITUATION FINANCIÈRE',
                        'objectifs' => [
                            'Rationaliser la dépense de bourses',
                            'Diminuer le nombre de boursiers à l’étranger',
                            'Générer des ressources propres',
                            'Rechercher des financements auprès des partenaires nationaux ou multilatéraux',
                        ],
                    ],
                    [
                        'code' => 'AXE-III',
                        'libelle' => 'ADAPTATION DES TEXTES AUX ENJEUX ACTUELS',
                        'objectifs' => [
                            'Réorganiser les missions et structures de l’agence',
                        ],
                    ],
                    [
                        'code' => 'AXE-IV',
                        'libelle' => 'AMÉLIORATION DE LA GOUVERNANCE ORGANISATIONNELLE',
                        'objectifs' => [
                            'Améliorer le cadre de planification stratégique',
                            'Mettre en place un monitoring des boursiers',
                            'Renforcer les capacités humaines et structurelles',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function seedCurrentWorkflowSamples(mixed $now): void
    {
        if (! Schema::hasTable('paos') || ! Schema::hasTable('pas') || ! Schema::hasTable('pas_objectifs')) {
            return;
        }

        $dafDirector = DB::table('users')
            ->where('email', 'directeur.daf@anbg.ga')
            ->first(['id', 'direction_id']);

        if ($dafDirector === null || $dafDirector->direction_id === null) {
            return;
        }

        $directionId = (int) $dafDirector->direction_id;
        $serviceId = DB::table('users')
            ->where('email', 'robert.ekomi@anbg.ga')
            ->where('direction_id', $directionId)
            ->value('service_id')
            ?? DB::table('services')
                ->where('direction_id', $directionId)
                ->where('actif', true)
                ->orderBy('id')
                ->value('id');

        if ($serviceId === null) {
            return;
        }

        $pasId = DB::table('pas')
            ->where('periode_debut', 2026)
            ->where('periode_fin', 2028)
            ->value('id');

        if ($pasId === null) {
            return;
        }

        $objectiveQuery = DB::table('pas_objectifs')
            ->join('pas_axes', 'pas_axes.id', '=', 'pas_objectifs.pas_axe_id')
            ->where('pas_axes.pas_id', (int) $pasId);

        $objectiveId = (clone $objectiveQuery)
            ->where('pas_objectifs.libelle', 'like', '%financements%')
            ->value('pas_objectifs.id')
            ?? (clone $objectiveQuery)->orderBy('pas_axes.ordre')->orderBy('pas_objectifs.ordre')->value('pas_objectifs.id');

        if ($objectiveId === null) {
            return;
        }

        $title = 'PAO DAF - validation financement 2026';
        $payload = $this->filterColumns('paos', [
            'exercice_id' => $this->exerciseId(2026),
            'pas_id' => (int) $pasId,
            'pas_objectif_id' => (int) $objectiveId,
            'direction_id' => $directionId,
            'service_id' => (int) $serviceId,
            'annee' => 2026,
            'titre' => $title,
            'echeance' => '2026-12-31',
            'objectif_operationnel' => 'Mettre en place le circuit de validation des financements d’actions.',
            'resultats_attendus' => 'Demandes de financement tracées, instruites par la DAF et validées selon le circuit DG.',
            'indicateurs_associes' => 'Délai de traitement, taux de dossiers complets, taux de validation.',
            'statut' => 'soumis',
            'valide_le' => null,
            'valide_par' => null,
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $existingId = DB::table('paos')
            ->where('annee', 2026)
            ->where('direction_id', $directionId)
            ->where('titre', $title)
            ->value('id');

        if ($existingId !== null) {
            DB::table('paos')->where('id', (int) $existingId)->update($payload);
            $paoId = (int) $existingId;
        } else {
            $payload['created_at'] = $now;
            $paoId = (int) DB::table('paos')->insertGetId($payload);
        }

        $ptaId = $this->upsertCurrentSubmittedPta($paoId, $directionId, (int) $serviceId, $now);

        if ($ptaId !== null) {
            $this->upsertCurrentAgentAction($ptaId, $directionId, (int) $serviceId, $now);
        }
    }

    private function syncObjectifsOperationnels(mixed $now): void
    {
        if (! Schema::hasTable('objectifs_operationnels')
            || ! Schema::hasTable('paos')
            || ! Schema::hasTable('pas_objectifs')
        ) {
            return;
        }

        $paos = DB::table('paos')
            ->leftJoin('pas_objectifs', 'pas_objectifs.id', '=', 'paos.pas_objectif_id')
            ->select([
                'paos.id',
                'paos.pas_id',
                'paos.pas_objectif_id',
                'pas_objectifs.pas_axe_id',
                'paos.direction_id',
                'paos.service_id',
                'paos.annee',
                'paos.titre',
                'paos.objectif_operationnel',
                'paos.resultats_attendus',
                'paos.echeance',
                'paos.indicateurs_associes',
                'paos.statut',
                'paos.created_at',
            ])
            ->whereNotNull('paos.objectif_operationnel')
            ->orderBy('paos.id')
            ->get();

        foreach ($paos as $pao) {
            if ($pao->pas_id === null
                || $pao->pas_objectif_id === null
                || $pao->pas_axe_id === null
                || $pao->direction_id === null
                || $pao->service_id === null
            ) {
                continue;
            }

            $libelle = trim((string) ($pao->objectif_operationnel ?: $pao->titre));
            if ($libelle === '') {
                continue;
            }

            $objectifId = DB::table('objectifs_operationnels')
                ->where('pao_id', (int) $pao->id)
                ->where('service_id', (int) $pao->service_id)
                ->where('libelle', $libelle)
                ->value('id');

            $payload = [
                'pao_id' => (int) $pao->id,
                'pas_id' => (int) $pao->pas_id,
                'pas_axe_id' => (int) $pao->pas_axe_id,
                'pas_objectif_id' => (int) $pao->pas_objectif_id,
                'direction_id' => (int) $pao->direction_id,
                'service_id' => (int) $pao->service_id,
                'libelle' => $libelle,
                'description' => $pao->resultats_attendus,
                'echeance' => $pao->echeance ?: ((int) ($pao->annee ?? date('Y'))).'-12-31',
                'indicateurs' => $pao->indicateurs_associes,
                'statut' => (string) ($pao->statut ?: 'brouillon'),
                'updated_at' => $now,
            ];

            if ($objectifId === null) {
                $payload['created_at'] = $pao->created_at ?? $now;
                $objectifId = DB::table('objectifs_operationnels')->insertGetId($payload);
            } else {
                DB::table('objectifs_operationnels')
                    ->where('id', (int) $objectifId)
                    ->update($payload);
            }

            if (Schema::hasColumn('ptas', 'objectif_operationnel_id')) {
                DB::table('ptas')
                    ->where('pao_id', (int) $pao->id)
                    ->where('service_id', (int) $pao->service_id)
                    ->update([
                        'objectif_operationnel_id' => (int) $objectifId,
                        'updated_at' => $now,
                    ]);
            }

            if (Schema::hasColumn('actions', 'objectif_operationnel_id')) {
                DB::table('actions')
                    ->whereIn('pta_id', function ($query) use ($pao): void {
                        $query->select('id')
                            ->from('ptas')
                            ->where('pao_id', (int) $pao->id)
                            ->where('service_id', (int) $pao->service_id);
                    })
                    ->update([
                        'pao_id' => (int) $pao->id,
                        'objectif_operationnel_id' => (int) $objectifId,
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    private function upsertCurrentSubmittedPta(int $paoId, int $directionId, int $serviceId, mixed $now): ?int
    {
        if (! Schema::hasTable('ptas')) {
            return null;
        }

        $payload = $this->filterColumns('ptas', [
            'exercice_id' => $this->exerciseId(2026),
            'pao_id' => $paoId,
            'direction_id' => $directionId,
            'service_id' => $serviceId,
            'titre' => 'PTA SFC - financement des actions 2026',
            'description' => 'PTA soumis pour contrôler le cycle de financement des actions DAF.',
            'statut' => 'soumis',
            'valide_le' => null,
            'valide_par' => null,
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $existingId = DB::table('ptas')
            ->where('pao_id', $paoId)
            ->value('id');

        if ($existingId !== null) {
            DB::table('ptas')->where('id', (int) $existingId)->update($payload);

            return (int) $existingId;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('ptas')->insertGetId($payload);
    }

    private function upsertCurrentAgentAction(int $ptaId, int $directionId, int $serviceId, mixed $now): void
    {
        if (! Schema::hasTable('actions')) {
            return;
        }

        $responsableId = DB::table('users')
            ->where('email', 'melissa.abogo@anbg.ga')
            ->where('direction_id', $directionId)
            ->value('id')
            ?? $this->resolveRoleUserId(User::ROLE_AGENT, $directionId, $serviceId);

        if ($responsableId === null) {
            return;
        }

        $title = 'Suivre les demandes de financement des actions 2026';
        $payload = $this->filterColumns('actions', [
            'exercice_id' => $this->exerciseId(2026),
            'pta_id' => $ptaId,
            'libelle' => $title,
            'description' => 'Action courante dediee au suivi des demandes de financement instruites par la DAF.',
            'type_cible' => 'quantitative',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 48,
            'resultat_attendu' => 'Demandes de financement traitees, justifiees et suivies dans les delais.',
            'criteres_validation' => 'Dossier complet, avis DAF trace et decision DG disponible si necessaire.',
            'livrable_attendu' => 'Etat mensuel de suivi des demandes de financement.',
            'date_debut' => '2026-04-01',
            'date_fin' => '2026-06-30',
            'date_echeance' => '2026-06-30',
            'responsable_id' => (int) $responsableId,
            'contexte_action' => 'operationnel',
            'origine_action' => 'PTA',
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'seuil_alerte_progression' => 10,
            'risques' => 'Retard de transmission des pieces justificatives.',
            'mesures_preventives' => 'Relance hebdomadaire et controle de completude des dossiers.',
            'financement_requis' => false,
            'financement_statut' => 'non_requis',
            'ressource_main_oeuvre' => true,
            'ressource_equipement' => false,
            'ressource_partenariat' => false,
            'ressource_autres' => false,
            'validation_hierarchique' => false,
            'validation_sans_correction' => false,
            'statut_validation' => 'non_soumise',
            'frequence_execution' => 'hebdomadaire',
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $actionId = DB::table('actions')
            ->where('pta_id', $ptaId)
            ->where('libelle', $title)
            ->value('id');

        if ($actionId !== null) {
            DB::table('actions')->where('id', (int) $actionId)->update($payload);
            $actionId = (int) $actionId;
        } else {
            $payload['created_at'] = $now;
            $actionId = (int) DB::table('actions')->insertGetId($payload);
        }

        $this->syncActionResponsables($actionId, [(int) $responsableId], (int) $responsableId, $now);
        $this->syncCurrentActionWeeks($actionId, (int) $responsableId, $now);
        $this->syncCurrentActionKpi($actionId, $now);
    }

    private function syncCurrentActionWeeks(int $actionId, int $responsableId, mixed $now): void
    {
        if (! Schema::hasTable('action_weeks')) {
            return;
        }

        $start = Carbon::create(2026, 4, 1);

        for ($weekNumber = 1; $weekNumber <= 4; $weekNumber++) {
            $weekStart = $start->copy()->addWeeks($weekNumber - 1);
            $weekEnd = $weekStart->copy()->addDays(6);

            DB::table('action_weeks')->updateOrInsert(
                [
                    'action_id' => $actionId,
                    'numero_semaine' => $weekNumber,
                ],
                [
                    'date_debut' => $weekStart->toDateString(),
                    'date_fin' => $weekEnd->toDateString(),
                    'est_renseignee' => false,
                    'quantite_realisee' => null,
                    'quantite_cumulee' => 0,
                    'taches_realisees' => null,
                    'avancement_estime' => null,
                    'commentaire' => null,
                    'difficultes' => null,
                    'mesures_correctives' => null,
                    'progression_reelle' => 0,
                    'progression_theorique' => 0,
                    'ecart_progression' => 0,
                    'saisi_par' => null,
                    'saisi_le' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function syncCurrentActionKpi(int $actionId, mixed $now): void
    {
        if (! Schema::hasTable('action_kpis')) {
            return;
        }

        DB::table('action_kpis')->updateOrInsert(
            ['action_id' => $actionId],
            $this->filterColumns('action_kpis', [
                'action_id' => $actionId,
                'kpi_delai' => 0,
                'kpi_performance' => 0,
                'kpi_conformite' => 100,
                'kpi_qualite' => 0,
                'kpi_global' => 0,
                'progression_reelle' => 0,
                'progression_theorique' => 0,
                'statut_calcule' => 'en_cours',
                'derniere_evaluation_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
        );
    }

    private function seedCurrentUserActionCoverage(?int $actorId, mixed $now): void
    {
        if (! Schema::hasTable('paos')
            || ! Schema::hasTable('ptas')
            || ! Schema::hasTable('actions')
            || ! Schema::hasTable('users')
        ) {
            return;
        }

        $pasId = DB::table('pas')
            ->where('periode_debut', 2026)
            ->where('periode_fin', 2028)
            ->value('id');

        if ($pasId === null) {
            return;
        }

        // Load all axes of this PAS, each with its first strategic objective.
        $axes = DB::table('pas_axes')
            ->where('pas_id', $pasId)
            ->orderBy('code')
            ->get(['id', 'code', 'libelle']);

        if ($axes->isEmpty()) {
            return;
        }

        $axisObjectivePairs = [];
        foreach ($axes as $axis) {
            $firstObjective = DB::table('pas_objectifs')
                ->where('pas_axe_id', $axis->id)
                ->orderBy('code')
                ->first(['id', 'code', 'libelle']);

            if ($firstObjective !== null) {
                $axisObjectivePairs[] = ['axis' => $axis, 'objective' => $firstObjective];
            }
        }

        if ($axisObjectivePairs === []) {
            return;
        }

        if (app()->environment('testing')) {
            $axisObjectivePairs = array_slice($axisObjectivePairs, 0, 1);
        }

        $users = $this->activeCoverageUsers();
        if ($users->isEmpty()) {
            return;
        }

        // Group users by direction+service. Users without a scope join the fallback.
        $serviceGroups = [];
        $orphanUsers = [];

        foreach ($users as $user) {
            $directionId = (int) ($user->direction_id ?? 0);
            $serviceId = (int) ($user->service_id ?? 0);

            if ($directionId > 0 && $serviceId > 0) {
                $serviceGroups[$directionId.'.'.$serviceId][] = $user;
            } else {
                $orphanUsers[] = $user;
            }
        }

        if ($orphanUsers !== []) {
            $fallbackScope = $this->fallbackOperationalScope();
            if ($fallbackScope !== null) {
                $key = $fallbackScope['direction_id'].'.'.$fallbackScope['service_id'];
                foreach ($orphanUsers as $user) {
                    $serviceGroups[$key][] = $user;
                }
            }
        }

        if ($serviceGroups === []) {
            return;
        }

        $this->syncObjectifsOperationnels($now);

        $fallbackActionId = null;

        foreach ($serviceGroups as $scopeKey => $scopeUsers) {
            [$directionId, $serviceId] = array_map('intval', explode('.', (string) $scopeKey));
            $scope = $this->scopeByIds($directionId, $serviceId);

            if ($scope === null) {
                continue;
            }

            foreach ($axisObjectivePairs as $pair) {
                $axis = $pair['axis'];
                $objective = $pair['objective'];

                // One PAO per axis per service, then one PTA per PAO.
                $paoId = $this->upsertCoveragePaoForAxis(
                    (int) $pasId, (int) $objective->id, $axis, $scope, $actorId, $now
                );

                $objectifOperationnelId = $this->resolveOperationalObjectiveForPao($paoId, $serviceId);

                $ptaId = $this->upsertCoveragePtaForAxis(
                    $paoId, $objectifOperationnelId, $axis, $scope, $actorId, $now
                );

                // One personal action per user per axis.
                foreach ($scopeUsers as $user) {
                    $actionId = $this->upsertUserAxisAction(
                        $ptaId, $paoId, $objectifOperationnelId, $axis, $scope, $user, $now
                    );
                    $fallbackActionId ??= $actionId;
                }
            }
        }

        if ($fallbackActionId !== null) {
            $this->ensurePasActionCoverage((int) $pasId, $fallbackActionId, false, $now);
        }
    }

    private function upsertCoveragePaoForAxis(
        int $pasId,
        int $objectiveId,
        object $axis,
        array $scope,
        ?int $actorId,
        mixed $now
    ): int {
        $serviceCode = (string) $scope['service_code'];
        $axisCode = (string) $axis->code;
        $title = 'PAO '.$serviceCode.' - '.$axisCode.' 2026';

        $payload = $this->filterColumns('paos', [
            'exercice_id' => $this->exerciseId(2026),
            'pas_id' => $pasId,
            'pas_objectif_id' => $objectiveId,
            'direction_id' => (int) $scope['direction_id'],
            'service_id' => (int) $scope['service_id'],
            'annee' => 2026,
            'titre' => $title,
            'echeance' => '2028-12-31',
            'objectif_operationnel' => 'Contribuer à : '.(string) $axis->libelle,
            'resultats_attendus' => 'Actions du service réalisées et consolidées dans le PTA '.$axisCode.'.',
            'indicateurs_associes' => 'Taux de réalisation, délais, qualité des livrables.',
            'statut' => 'valide',
            'valide_le' => $now,
            'valide_par' => $actorId,
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $paoId = DB::table('paos')
            ->where('annee', 2026)
            ->where('direction_id', (int) $scope['direction_id'])
            ->where('service_id', (int) $scope['service_id'])
            ->where('titre', $title)
            ->value('id');

        if ($paoId !== null) {
            DB::table('paos')->where('id', (int) $paoId)->update($payload);

            return (int) $paoId;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('paos')->insertGetId($payload);
    }

    private function upsertCoveragePtaForAxis(
        int $paoId,
        ?int $objectifOperationnelId,
        object $axis,
        array $scope,
        ?int $actorId,
        mixed $now
    ): int {
        $serviceCode = (string) $scope['service_code'];
        $axisCode = (string) $axis->code;
        $title = 'PTA '.$serviceCode.' - '.$axisCode.' 2026';

        $payload = $this->filterColumns('ptas', [
            'exercice_id' => $this->exerciseId(2026),
            'pao_id' => $paoId,
            'objectif_operationnel_id' => $objectifOperationnelId,
            'direction_id' => (int) $scope['direction_id'],
            'service_id' => (int) $scope['service_id'],
            'titre' => $title,
            'description' => 'PTA de service pour l\'axe '.$axisCode.' du PAS ANBG 2026-2028.',
            'statut' => 'valide',
            'valide_le' => $now,
            'valide_par' => $actorId,
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $ptaId = DB::table('ptas')->where('pao_id', $paoId)->value('id');

        if ($ptaId !== null) {
            DB::table('ptas')->where('id', (int) $ptaId)->update($payload);

            return (int) $ptaId;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('ptas')->insertGetId($payload);
    }

    private function upsertUserAxisAction(
        int $ptaId,
        int $paoId,
        ?int $objectifOperationnelId,
        object $axis,
        array $scope,
        object $user,
        mixed $now
    ): int {
        $axisCode = (string) $axis->code;
        $userName = $this->shortUserLabel($user);
        $serviceCode = (string) $scope['service_code'];
        $userId = (int) $user->id;

        $actionLabel = $axisCode.' : contribution '.$serviceCode.' - '.$userName;
        $description = 'Action personnelle pour '.$userName.' dans le cadre de l\'axe '.$axisCode.' : '.(string) $axis->libelle;

        $payload = $this->filterColumns('actions', [
            'exercice_id' => $this->exerciseId(2026),
            'pta_id' => $ptaId,
            'pao_id' => $paoId,
            'objectif_operationnel_id' => $objectifOperationnelId,
            'mode_evaluation' => Action::MODE_MIXTE,
            'libelle' => $actionLabel,
            'description' => $description,
            'type_cible' => 'quantitative',
            'priorite' => 'normale',
            'unite_cible' => 'taches',
            'quantite_cible' => 4,
            'quantite_realisee' => 0,
            'resultat_attendu' => 'Livrables produits, suivis et consolidés dans le reporting '.$axisCode.'.',
            'indicateurs_attendus' => 'Taux de réalisation, respect des délais, qualité des livrables.',
            'observations' => 'Action individuelle créée par le seeder institutionnel.',
            'date_debut' => '2026-01-01',
            'date_fin' => '2028-12-31',
            'date_echeance' => '2028-12-31',
            'responsable_id' => $userId,
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'origine_action' => Action::ORIGIN_PTA,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'avancement_operationnel' => 0,
            'taux_atteinte_cible' => 0,
            'taux_global' => 0,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
            'financement_statut' => Action::FINANCEMENT_NON_REQUIS,
            'ressource_main_oeuvre' => true,
            'ressource_equipement' => false,
            'ressource_partenariat' => false,
            'ressource_autres' => false,
            'ressources_necessaires' => json_encode(['main_oeuvre'], JSON_UNESCAPED_UNICODE),
            'validation_hierarchique' => false,
            'validation_sans_correction' => false,
            'statut_validation' => 'non_soumise',
            'frequence_execution' => 'hebdomadaire',
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $actionId = DB::table('actions')
            ->where('pta_id', $ptaId)
            ->where('responsable_id', $userId)
            ->where('libelle', $actionLabel)
            ->value('id');

        if ($actionId !== null) {
            DB::table('actions')->where('id', (int) $actionId)->update($payload);
            $actionId = (int) $actionId;
        } else {
            $payload['created_at'] = $now;
            $actionId = (int) DB::table('actions')->insertGetId($payload);
        }

        $this->syncActionResponsables($actionId, [$userId], $userId, $now);
        $this->syncCurrentActionKpi($actionId, $now);

        return $actionId;
    }

    private function upsertCoveragePao(
        int $pasId,
        int $objectiveId,
        array $scope,
        bool $transversal,
        ?int $actorId,
        mixed $now
    ): int {
        $directionCode = (string) $scope['direction_code'];
        $serviceCode = (string) $scope['service_code'];
        $title = $transversal
            ? 'PAO transversal - controle du PAS 2026'
            : 'PAO '.$directionCode.' - contribution '.$serviceCode.' 2026';

        $objectiveLabel = $transversal
            ? 'Assurer le controle transversal et la coordination du PAS 2026-2028.'
            : 'Piloter la contribution '.$serviceCode.' au PAS ANBG 2026-2028.';

        $payload = $this->filterColumns('paos', [
            'exercice_id' => $this->exerciseId(2026),
            'pas_id' => $pasId,
            'pas_objectif_id' => $objectiveId,
            'direction_id' => (int) $scope['direction_id'],
            'service_id' => (int) $scope['service_id'],
            'annee' => 2026,
            'titre' => $title,
            'echeance' => '2026-12-31',
            'objectif_operationnel' => $objectiveLabel,
            'resultats_attendus' => $transversal
                ? 'Circuit de controle, arbitrage et suivi operationnel alimente par tous les profils concernes.'
                : 'Actions du service affectees, suivies et consolidees dans le PTA.',
            'indicateurs_associes' => $transversal
                ? 'Taux de suivi des controles, delais de revue, delegations actives.'
                : 'Taux de taches realisees, taux de suivi des actions, delais de production.',
            'statut' => 'valide',
            'valide_le' => $now,
            'valide_par' => $actorId,
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $paoId = DB::table('paos')
            ->where('annee', 2026)
            ->where('direction_id', (int) $scope['direction_id'])
            ->where('service_id', (int) $scope['service_id'])
            ->where('titre', $title)
            ->value('id');

        if ($paoId !== null) {
            DB::table('paos')->where('id', (int) $paoId)->update($payload);

            return (int) $paoId;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('paos')->insertGetId($payload);
    }

    private function upsertCoveragePta(
        int $paoId,
        ?int $objectifOperationnelId,
        array $scope,
        bool $transversal,
        ?int $actorId,
        mixed $now
    ): int {
        $serviceCode = (string) $scope['service_code'];
        $payload = $this->filterColumns('ptas', [
            'exercice_id' => $this->exerciseId(2026),
            'pao_id' => $paoId,
            'objectif_operationnel_id' => $objectifOperationnelId,
            'direction_id' => (int) $scope['direction_id'],
            'service_id' => (int) $scope['service_id'],
            'titre' => $transversal ? 'PTA transversal - controle PAS 2026' : 'PTA '.$serviceCode.' - actions PAS 2026',
            'description' => $transversal
                ? 'PTA de coordination pour les profils de controle et de pilotage.'
                : 'PTA de service alimente par le seeder institutionnel pour affecter les actions aux utilisateurs.',
            'statut' => 'valide',
            'valide_le' => $now,
            'valide_par' => $actorId,
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $ptaId = DB::table('ptas')->where('pao_id', $paoId)->value('id');

        if ($ptaId !== null) {
            DB::table('ptas')->where('id', (int) $ptaId)->update($payload);

            return (int) $ptaId;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('ptas')->insertGetId($payload);
    }

    private function upsertCoverageAction(
        int $ptaId,
        int $paoId,
        ?int $objectifOperationnelId,
        array $scope,
        mixed $assignedUsers,
        bool $transversal,
        mixed $now
    ): int {
        $serviceCode = (string) $scope['service_code'];
        $title = $transversal
            ? 'Controler le suivi institutionnel du PAS 2026-2028'
            : 'Executer et suivre les actions '.$serviceCode.' du PAS 2026-2028';
        $primaryId = $this->chooseCoveragePrimaryUser($assignedUsers);
        $userIds = $assignedUsers->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $target = max(1, count($userIds)) * 4;

        $payload = $this->filterColumns('actions', [
            'exercice_id' => $this->exerciseId(2026),
            'pta_id' => $ptaId,
            'pao_id' => $paoId,
            'objectif_operationnel_id' => $objectifOperationnelId,
            'mode_evaluation' => Action::MODE_MIXTE,
            'libelle' => $title,
            'description' => $transversal
                ? 'Action transversale assignee aux profils de pilotage, de controle et d administration.'
                : 'Action de service assignee a tous les utilisateurs actifs du perimetre.',
            'type_cible' => 'quantitative',
            'priorite' => 'haute',
            'unite_cible' => 'taches',
            'quantite_cible' => $target,
            'quantite_realisee' => 0,
            'resultat_attendu' => 'Taches suivies, justificatifs consolides et reporting disponible.',
            'indicateurs_attendus' => 'Taux de taches realisees, completude des suivis, respect des delais.',
            'observations' => 'Action de couverture creee par le seeder institutionnel.',
            'date_debut' => '2026-05-01',
            'date_fin' => '2026-12-31',
            'date_echeance' => '2026-12-31',
            'responsable_id' => $primaryId,
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'origine_action' => Action::ORIGIN_PTA,
            'statut' => 'en_cours',
            'statut_dynamique' => 'en_cours',
            'progression_reelle' => 0,
            'progression_theorique' => 0,
            'avancement_operationnel' => 0,
            'taux_atteinte_cible' => 0,
            'taux_global' => 0,
            'seuil_alerte_progression' => 10,
            'risques' => 'Retard de saisie ou absence de justificatif.',
            'risque_potentiel' => 'Decalage entre execution terrain et consolidation institutionnelle.',
            'niveau_risque' => 'modere',
            'impact_estime' => 'Reporting incomplet',
            'probabilite' => 'moyenne',
            'mesures_preventives' => 'Relances planifiees, controle hebdomadaire et delegation active en cas d absence.',
            'responsable_suivi_risque' => $transversal ? 'Planification' : $serviceCode,
            'financement_requis' => false,
            'financement_statut' => Action::FINANCEMENT_NON_REQUIS,
            'ressource_main_oeuvre' => true,
            'ressource_equipement' => false,
            'ressource_partenariat' => false,
            'ressource_autres' => false,
            'ressources_necessaires' => json_encode(['main_oeuvre'], JSON_UNESCAPED_UNICODE),
            'ressources_details' => 'Mobilisation des utilisateurs rattaches a l action.',
            'validation_hierarchique' => false,
            'validation_sans_correction' => false,
            'statut_validation' => 'non_soumise',
            'frequence_execution' => 'hebdomadaire',
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $actionId = DB::table('actions')
            ->where('pta_id', $ptaId)
            ->where('libelle', $title)
            ->value('id');

        if ($actionId !== null) {
            DB::table('actions')->where('id', (int) $actionId)->update($payload);
            $actionId = (int) $actionId;
        } else {
            $payload['created_at'] = $now;
            $actionId = (int) DB::table('actions')->insertGetId($payload);
        }

        $this->syncActionResponsables($actionId, $userIds, $primaryId, $now);
        $this->syncCoverageSousActions($actionId, $assignedUsers, false, $now, 'Tache PAS 2026', '2026-05-01', '2026-12-31');
        if ($primaryId !== null) {
            $this->syncCurrentActionWeeks($actionId, $primaryId, $now);
        }
        $this->syncCurrentActionKpi($actionId, $now);

        return $actionId;
    }

    private function seedTaskDelegations(?int $actorId, mixed $now): void
    {
        if (! Schema::hasTable('delegations')) {
            return;
        }

        foreach ($this->taskDelegationItems() as $item) {
            $delegantId = $this->userIdByEmail((string) $item['delegant_email']);
            $delegueId = $this->userIdByEmail((string) $item['delegue_email']);
            $directionId = DB::table('directions')->where('code', (string) $item['direction_code'])->value('id');
            $serviceId = null;

            if (($item['service_code'] ?? null) !== null) {
                $serviceId = DB::table('services')
                    ->join('directions', 'directions.id', '=', 'services.direction_id')
                    ->where('directions.code', (string) $item['direction_code'])
                    ->where('services.code', (string) $item['service_code'])
                    ->value('services.id');
            }

            if ($delegantId === null || $delegueId === null || $directionId === null) {
                continue;
            }

            if ($item['role_scope'] === Delegation::SCOPE_SERVICE && $serviceId === null) {
                continue;
            }

            DB::table('delegations')->updateOrInsert(
                [
                    'delegant_id' => $delegantId,
                    'delegue_id' => $delegueId,
                    'role_scope' => (string) $item['role_scope'],
                    'direction_id' => (int) $directionId,
                    'service_id' => $serviceId !== null ? (int) $serviceId : null,
                ],
                $this->filterColumns('delegations', [
                    'permissions' => json_encode($item['permissions'], JSON_UNESCAPED_UNICODE),
                    'motif' => (string) $item['motif'],
                    'date_debut' => Carbon::parse($now)->subDay(),
                    'date_fin' => Carbon::parse($now)->addDays(45),
                    'statut' => 'active',
                    'cree_par' => $actorId,
                    'annule_par' => null,
                    'annule_le' => null,
                    'motif_annulation' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taskDelegationItems(): array
    {
        return [
            [
                'delegant_email' => 'directeur.daf@anbg.ga',
                'delegue_email' => 'robert.ekomi@anbg.ga',
                'role_scope' => Delegation::SCOPE_DIRECTION,
                'direction_code' => 'DAF',
                'service_code' => null,
                'permissions' => ['planning_read', 'action_review'],
                'motif' => 'Delegation de controle direction DAF pour le suivi des actions PAS.',
            ],
            [
                'delegant_email' => 'robert.ekomi@anbg.ga',
                'delegue_email' => 'melissa.abogo@anbg.ga',
                'role_scope' => Delegation::SCOPE_SERVICE,
                'direction_code' => 'DAF',
                'service_code' => 'SFC',
                'permissions' => ['planning_read', 'action_review'],
                'motif' => 'Delegation de revue service SFC pour les taches de suivi.',
            ],
            [
                'delegant_email' => 'directeur.dsic@anbg.ga',
                'delegue_email' => 'arnold.mindzeli@anbg.ga',
                'role_scope' => Delegation::SCOPE_DIRECTION,
                'direction_code' => 'DSIC',
                'service_code' => null,
                'permissions' => ['planning_read', 'action_review'],
                'motif' => 'Delegation de controle direction DSIC pour le suivi des actions PAS.',
            ],
            [
                'delegant_email' => 'arnold.mindzeli@anbg.ga',
                'delegue_email' => 'francois.camara@anbg.ga',
                'role_scope' => Delegation::SCOPE_SERVICE,
                'direction_code' => 'DSIC',
                'service_code' => 'SIRS',
                'permissions' => ['planning_read', 'action_review'],
                'motif' => 'Delegation de revue service SIRS pour les taches de suivi.',
            ],
            [
                'delegant_email' => 'directeur.ds@anbg.ga',
                'delegue_email' => 'codjo.menoueton@anbg.ga',
                'role_scope' => Delegation::SCOPE_DIRECTION,
                'direction_code' => 'DS',
                'service_code' => null,
                'permissions' => ['planning_read', 'action_review'],
                'motif' => 'Delegation de controle direction DS pour le suivi des actions PAS.',
            ],
            [
                'delegant_email' => 'codjo.menoueton@anbg.ga',
                'delegue_email' => 'belinda.magnangani@anbg.ga',
                'role_scope' => Delegation::SCOPE_SERVICE,
                'direction_code' => 'DS',
                'service_code' => 'EB',
                'permissions' => ['planning_read', 'action_review'],
                'motif' => 'Delegation de revue service EB pour les taches de suivi.',
            ],
            [
                'delegant_email' => 'loick.adan@anbg.ga',
                'delegue_email' => 'hilaire.nguebet@anbg.ga',
                'role_scope' => Delegation::SCOPE_SERVICE,
                'direction_code' => 'DG',
                'service_code' => 'SCIQ',
                'permissions' => ['planning_read', 'action_review'],
                'motif' => 'Delegation SCIQ pour la coordination et le controle transversal.',
            ],
        ];
    }

    private function activeCoverageUsers()
    {
        $query = DB::table('users')
            ->select(['id', 'name', 'email', 'role', 'direction_id', 'service_id'])
            ->orderBy('role')
            ->orderBy('direction_id')
            ->orderBy('service_id')
            ->orderBy('id');

        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if (app()->environment('testing')) {
            $query->whereIn('email', [
                'ingrid@anbg.ga',
                'loick.adan@anbg.ga',
                'hilaire.nguebet@anbg.ga',
                'directeur.daf@anbg.ga',
                'robert.ekomi@anbg.ga',
                'melissa.abogo@anbg.ga',
            ]);
        }

        return $query->get();
    }

    private function coverageUsersForScope(int $directionId, int $serviceId)
    {
        return $this->activeCoverageUsers()
            ->filter(static fn ($user): bool => (int) ($user->direction_id ?? 0) === $directionId
                && (int) ($user->service_id ?? 0) === $serviceId)
            ->values();
    }

    private function syncActionResponsables(int $actionId, array $userIds, ?int $primaryUserId, mixed $now): void
    {
        if (! Schema::hasTable('action_responsables')) {
            return;
        }

        $userIds = collect($userIds)
            ->push($primaryUserId)
            ->filter(static fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return;
        }

        DB::table('action_responsables')
            ->where('action_id', $actionId)
            ->whereNotIn('user_id', $userIds)
            ->delete();

        foreach ($userIds as $userId) {
            DB::table('action_responsables')->updateOrInsert(
                [
                    'action_id' => $actionId,
                    'user_id' => $userId,
                ],
                [
                    'is_primary' => $primaryUserId !== null && $userId === (int) $primaryUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function syncCoverageSousActions(
        int $actionId,
        mixed $users,
        bool $completed,
        mixed $now,
        string $labelPrefix,
        string $dateStart,
        string $dateEnd
    ): void {
        if (! Schema::hasTable('sous_actions')) {
            return;
        }

        foreach ($users as $user) {
            $label = $labelPrefix.' - '.$this->shortUserLabel($user);

            DB::table('sous_actions')->updateOrInsert(
                [
                    'action_id' => $actionId,
                    'agent_id' => (int) $user->id,
                ],
                $this->filterColumns('sous_actions', [
                    'libelle' => $label,
                    'description' => $completed
                        ? 'Tache finalisee dans le cadre du PAS precedent.'
                        : 'Tache affectee pour execution et suivi dans le PAS en cours.',
                    'resultat_attendu' => $completed
                        ? 'Contribution cloturee et archivee.'
                        : 'Contribution renseignee et suivie dans les delais.',
                    'commentaire' => $completed
                        ? 'Donnee historique marquee comme achevee par le seeder institutionnel.'
                        : 'A suivre par le responsable et les profils de controle.',
                    'date_debut' => $dateStart,
                    'date_fin' => $dateEnd,
                    'date_realisation' => $completed ? Carbon::parse($dateEnd)->setTime(17, 0) : null,
                    'statut' => $completed ? 'termine' : 'a_faire',
                    'est_effectuee' => $completed,
                    'taux_execution' => $completed ? 100 : 0,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }

    private function ensurePasActionCoverage(int $pasId, int $fallbackActionId, bool $completed, mixed $now): void
    {
        $users = $this->activeCoverageUsers();
        $allUserIds = $users->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        if ($allUserIds === []) {
            return;
        }

        $actionIds = DB::table('actions')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->join('paos', 'paos.id', '=', 'ptas.pao_id')
            ->where('paos.pas_id', $pasId)
            ->when(Schema::hasColumn('actions', 'deleted_at'), fn ($query) => $query->whereNull('actions.deleted_at'))
            ->pluck('actions.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($actionIds === []) {
            return;
        }

        $coveredUserIds = collect(DB::table('actions')
            ->whereIn('id', $actionIds)
            ->whereNotNull('responsable_id')
            ->pluck('responsable_id')
            ->all());

        if (Schema::hasTable('action_responsables')) {
            $coveredUserIds = $coveredUserIds->merge(
                DB::table('action_responsables')
                    ->whereIn('action_id', $actionIds)
                    ->pluck('user_id')
                    ->all()
            );
        }

        $coveredUserIds = $coveredUserIds
            ->filter(static fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $missingUserIds = array_values(array_diff($allUserIds, $coveredUserIds));
        if ($missingUserIds === []) {
            return;
        }

        $fallbackUserIds = Schema::hasTable('action_responsables')
            ? DB::table('action_responsables')
                ->where('action_id', $fallbackActionId)
                ->pluck('user_id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
            : [];

        $primaryUserId = DB::table('actions')->where('id', $fallbackActionId)->value('responsable_id');
        $fallbackUserIds = collect($fallbackUserIds)
            ->merge($missingUserIds)
            ->push($primaryUserId)
            ->filter(static fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $fallbackUsers = $users
            ->filter(fn ($user): bool => in_array((int) $user->id, $fallbackUserIds, true))
            ->values();

        $this->syncActionResponsables(
            $fallbackActionId,
            $fallbackUserIds,
            $primaryUserId !== null ? (int) $primaryUserId : ($fallbackUserIds[0] ?? null),
            $now
        );
        $this->syncCoverageSousActions(
            $fallbackActionId,
            $fallbackUsers,
            $completed,
            $now,
            $completed ? 'Contribution historique cloturee' : 'Tache transversale PAS',
            $completed ? '2025-01-01' : '2026-05-01',
            $completed ? '2025-12-31' : '2026-12-31'
        );
    }

    private function resolveOperationalObjectiveForPao(int $paoId, int $serviceId): ?int
    {
        if (! Schema::hasTable('objectifs_operationnels')) {
            return null;
        }

        $id = DB::table('objectifs_operationnels')
            ->where('pao_id', $paoId)
            ->where('service_id', $serviceId)
            ->orderBy('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function currentObjectiveKeyForScope(string $directionCode, string $serviceCode): string
    {
        if ($directionCode === 'DAF') {
            return 'AXE-II.OBJ-1';
        }

        if ($directionCode === 'DSIC') {
            return 'AXE-IV.OBJ-2';
        }

        if ($directionCode === 'DS') {
            return 'AXE-I.OBJ-1';
        }

        if ($directionCode === 'DIR021') {
            return 'AXE-III.OBJ-1';
        }

        if ($directionCode === 'DG' && $serviceCode === 'CAB') {
            return 'AXE-IV.OBJ-1';
        }

        if ($directionCode === 'DG') {
            return 'AXE-IV.OBJ-3';
        }

        return 'AXE-I.OBJ-1';
    }

    private function chooseCoveragePrimaryUser(mixed $users): ?int
    {
        foreach ([User::ROLE_SERVICE, User::ROLE_DIRECTION, User::ROLE_PLANIFICATION, User::ROLE_AGENT, User::ROLE_CABINET, User::ROLE_DG, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $role) {
            $user = $users->first(static fn ($item): bool => (string) ($item->role ?? '') === $role);
            if ($user !== null) {
                return (int) $user->id;
            }
        }

        $first = $users->first();

        return $first !== null ? (int) $first->id : null;
    }

    private function scopeByIds(int $directionId, int $serviceId): ?array
    {
        $row = DB::table('services')
            ->join('directions', 'directions.id', '=', 'services.direction_id')
            ->where('directions.id', $directionId)
            ->where('services.id', $serviceId)
            ->first([
                'directions.id as direction_id',
                'directions.code as direction_code',
                'directions.libelle as direction_label',
                'services.id as service_id',
                'services.code as service_code',
                'services.libelle as service_label',
            ]);

        if ($row === null) {
            return null;
        }

        return [
            'direction_id' => (int) $row->direction_id,
            'direction_code' => (string) $row->direction_code,
            'direction_label' => (string) $row->direction_label,
            'service_id' => (int) $row->service_id,
            'service_code' => (string) $row->service_code,
            'service_label' => (string) $row->service_label,
        ];
    }

    private function fallbackOperationalScope(): ?array
    {
        $preferred = $this->resolveDirectionService('DG', 'SCIQ');
        if ($preferred !== null) {
            return $this->scopeByIds((int) $preferred['direction_id'], (int) $preferred['service_id']);
        }

        $row = DB::table('services')
            ->join('directions', 'directions.id', '=', 'services.direction_id')
            ->where('services.actif', true)
            ->orderBy('directions.code')
            ->orderBy('services.code')
            ->first(['directions.id as direction_id', 'services.id as service_id']);

        if ($row === null) {
            return null;
        }

        return $this->scopeByIds((int) $row->direction_id, (int) $row->service_id);
    }

    private function userIdByEmail(string $email): ?int
    {
        $id = DB::table('users')->where('email', strtolower($email))->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function shortUserLabel(mixed $user): string
    {
        $label = trim((string) ($user->name ?? ''));

        if ($label === '') {
            $label = trim((string) ($user->email ?? 'Utilisateur'));
        }

        return $label;
    }

    private function actorId(): ?int
    {
        return DB::table('users')
            ->whereIn('email', ['superadmin@anbg.ga', 'admin@anbg.ga'])
            ->orderByRaw("CASE WHEN email = 'superadmin@anbg.ga' THEN 0 ELSE 1 END")
            ->value('id');
    }

    private function seedExercises(mixed $now): void
    {
        if (! Schema::hasTable('exercices')) {
            return;
        }

        $currentYear = (int) now()->year;

        foreach (range(2020, 2028) as $year) {
            DB::table('exercices')->updateOrInsert(
                ['annee' => $year],
                [
                    'libelle' => 'Exercice '.$year,
                    'date_debut' => Carbon::create($year, 1, 1)->toDateString(),
                    'date_fin' => Carbon::create($year, 12, 31)->toDateString(),
                    'statut' => $year < $currentYear ? Exercice::STATUT_ARCHIVE : Exercice::STATUT_OUVERT,
                    'is_active' => $year === $currentYear,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $pasData
     */
    private function upsertPas(array $pasData, ?int $actorId, mixed $now): int
    {
        $start = (int) $pasData['periode_debut'];
        $end = (int) $pasData['periode_fin'];
        $titre = (string) $pasData['titre'];

        $payload = [
            'titre' => $titre,
            'periode_debut' => $start,
            'periode_fin' => $end,
            'statut' => (string) $pasData['statut'],
            'valide_le' => in_array($pasData['statut'], ['valide', 'verrouille'], true) ? $now : null,
            'valide_par' => $actorId,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('pas', 'created_by')) {
            $payload['created_by'] = $actorId;
        }

        if (Schema::hasColumn('pas', 'exercice_id')) {
            $payload['exercice_id'] = $this->exerciseId($start);
        }

        if (Schema::hasColumn('pas', 'deleted_at')) {
            $payload['deleted_at'] = null;
        }

        $pasId = DB::table('pas')
            ->where('periode_debut', $start)
            ->where('periode_fin', $end)
            ->orderBy('id')
            ->value('id');

        if ($pasId !== null) {
            DB::table('pas')
                ->where('id', (int) $pasId)
                ->update($payload);

            return (int) $pasId;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('pas')->insertGetId($payload);
    }

    private function exerciseId(int $year): ?int
    {
        if (! Schema::hasTable('exercices')) {
            return null;
        }

        $id = DB::table('exercices')->where('annee', $year)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param array<int, int> $directionIds
     */
    private function syncPasDirections(int $pasId, array $directionIds, mixed $now): void
    {
        if (! Schema::hasTable('pas_directions')) {
            return;
        }

        DB::table('pas_directions')
            ->where('pas_id', $pasId)
            ->whereNotIn('direction_id', $directionIds)
            ->delete();

        foreach ($directionIds as $directionId) {
            DB::table('pas_directions')->updateOrInsert(
                [
                    'pas_id' => $pasId,
                    'direction_id' => $directionId,
                ],
                [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $pasData
     */
    private function syncAxes(int $pasId, array $pasData, ?int $actorId, mixed $now): void
    {
        $validAxisCodes = array_map(
            static fn (array $axis): string => (string) $axis['code'],
            $pasData['axes']
        );

        DB::table('pas_axes')
            ->where('pas_id', $pasId)
            ->whereNotIn('code', $validAxisCodes)
            ->delete();

        foreach (array_values($pasData['axes']) as $axisIndex => $axis) {
            $axisId = $this->upsertAxis($pasId, $pasData, $axis, $axisIndex + 1, $actorId, $now);
            $this->syncObjectifs($axisId, $pasData, $axis, $actorId, $now);
        }
    }

    /**
     * @param array<string, mixed> $pasData
     * @param array<string, mixed> $axis
     */
    private function upsertAxis(
        int $pasId,
        array $pasData,
        array $axis,
        int $order,
        ?int $actorId,
        mixed $now
    ): int {
        $start = (int) $pasData['periode_debut'];
        $end = (int) $pasData['periode_fin'];

        $payload = [
            'pas_id' => $pasId,
            'code' => (string) $axis['code'],
            'libelle' => (string) $axis['libelle'],
            'description' => 'Échéance : '.$start.'-'.$end,
            'ordre' => $order,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('pas_axes', 'direction_id')) {
            $payload['direction_id'] = null;
        }

        if (Schema::hasColumn('pas_axes', 'periode_debut')) {
            $payload['periode_debut'] = Carbon::create($start, 1, 1)->toDateString();
        }

        if (Schema::hasColumn('pas_axes', 'periode_fin')) {
            $payload['periode_fin'] = Carbon::create($end, 12, 31)->toDateString();
        }

        if (Schema::hasColumn('pas_axes', 'created_by')) {
            $payload['created_by'] = $actorId;
        }

        if (Schema::hasColumn('pas_axes', 'deleted_at')) {
            $payload['deleted_at'] = null;
        }

        $axisId = DB::table('pas_axes')
            ->where('pas_id', $pasId)
            ->where('code', (string) $axis['code'])
            ->value('id');

        if ($axisId !== null) {
            DB::table('pas_axes')
                ->where('id', (int) $axisId)
                ->update($payload);

            return (int) $axisId;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('pas_axes')->insertGetId($payload);
    }

    /**
     * @param array<string, mixed> $pasData
     * @param array<string, mixed> $axis
     */
    private function syncObjectifs(int $axisId, array $pasData, array $axis, ?int $actorId, mixed $now): void
    {
        $start = (int) $pasData['periode_debut'];
        $end = (int) $pasData['periode_fin'];
        $targetLabel = $start.'-'.$end;

        $objectifs = array_values($axis['objectifs']);
        $validObjectiveCodes = array_map(
            static fn (int $index): string => 'OBJ-'.($index + 1),
            array_keys($objectifs)
        );

        DB::table('pas_objectifs')
            ->where('pas_axe_id', $axisId)
            ->whereNotIn('code', $validObjectiveCodes)
            ->delete();

        foreach ($objectifs as $index => $libelle) {
            $code = 'OBJ-'.($index + 1);
            $payload = [
                'pas_axe_id' => $axisId,
                'code' => $code,
                'libelle' => (string) $libelle,
                'description' => 'Objectif stratégique '.$code.' du PAS '.$targetLabel,
                'indicateur_global' => 'Suivi de réalisation stratégique',
                'valeur_cible' => $targetLabel,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('pas_objectifs', 'ordre')) {
                $payload['ordre'] = $index + 1;
            }

            if (Schema::hasColumn('pas_objectifs', 'valeurs_cible')) {
                $payload['valeurs_cible'] = json_encode([
                    'echeance' => $targetLabel,
                    'statut_cible' => 'réalisé',
                ], JSON_UNESCAPED_UNICODE);
            }

            if (Schema::hasColumn('pas_objectifs', 'created_by')) {
                $payload['created_by'] = $actorId;
            }

            if (Schema::hasColumn('pas_objectifs', 'deleted_at')) {
                $payload['deleted_at'] = null;
            }

            $objectifId = DB::table('pas_objectifs')
                ->where('pas_axe_id', $axisId)
                ->where('code', $code)
                ->value('id');

            if ($objectifId !== null) {
                DB::table('pas_objectifs')
                    ->where('id', (int) $objectifId)
                    ->update($payload);

                continue;
            }

            $payload['created_at'] = $now;
            DB::table('pas_objectifs')->insert($payload);
        }
    }

    private function seedCompletedLegacyActions(int $pasId, ?int $actorId, mixed $now): void
    {
        if (! Schema::hasTable('paos') || ! Schema::hasTable('ptas') || ! Schema::hasTable('actions')) {
            return;
        }

        $objectiveIds = $this->objectiveIdsByKey($pasId);
        $exerciseId = $this->exerciseId(2025);
        $fallbackActionId = null;
        $items = $this->completedLegacyActionItems();

        if (app()->environment('testing')) {
            $items = array_slice($items, 0, 3);
        }

        foreach ($items as $item) {
            $objectiveId = $objectiveIds[$item['objectif_key']] ?? null;
            if ($objectiveId === null) {
                continue;
            }

            $scope = $this->resolveDirectionService($item['direction_code'], $item['service_code']);
            if ($scope === null) {
                continue;
            }

            $responsableId = $this->resolveResponsableId(
                $item['responsable_email'] ?? null,
                (int) $scope['direction_id'],
                (int) $scope['service_id']
            );
            $chefId = $this->resolveRoleUserId(User::ROLE_SERVICE, (int) $scope['direction_id'], (int) $scope['service_id']);
            $directeurId = $this->resolveDirectionValidatorId((int) $scope['direction_id']) ?? $actorId;
            $validatorId = $directeurId ?? $actorId;

            $paoId = $this->upsertLegacyPao(
                $pasId,
                (int) $objectiveId,
                (int) $scope['direction_id'],
                (int) $scope['service_id'],
                $exerciseId,
                $item,
                $validatorId,
                $now
            );

            $ptaId = $this->upsertLegacyPta(
                $paoId,
                (int) $scope['direction_id'],
                (int) $scope['service_id'],
                $exerciseId,
                $item,
                $chefId ?? $validatorId,
                $now
            );

            $actionId = $this->upsertCompletedLegacyAction(
                $ptaId,
                $exerciseId,
                $item,
                $responsableId,
                $chefId ?? $validatorId,
                $validatorId,
                $now
            );

            $this->syncLegacyActionWeeks($actionId, $item, $responsableId, $now);
            $this->syncLegacyActionKpis($actionId, $exerciseId, $item, $responsableId, $now);
            $this->syncLegacyActionLogs($actionId, $item, $responsableId, $chefId ?? $validatorId, $validatorId, $now);

            $scopeUsers = $this->coverageUsersForScope((int) $scope['direction_id'], (int) $scope['service_id']);
            $this->syncActionResponsables(
                $actionId,
                $scopeUsers->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                $responsableId,
                $now
            );
            $this->syncCoverageSousActions(
                $actionId,
                $scopeUsers,
                true,
                $now,
                'Contribution historique cloturee',
                (string) $item['date_debut'],
                (string) $item['date_fin_reelle']
            );

            $fallbackActionId ??= $actionId;
        }

        if ($fallbackActionId !== null) {
            $this->ensurePasActionCoverage($pasId, $fallbackActionId, true, $now);
        }
    }

    /**
     * @return array<string, int>
     */
    private function objectiveIdsByKey(int $pasId): array
    {
        return DB::table('pas_objectifs')
            ->join('pas_axes', 'pas_axes.id', '=', 'pas_objectifs.pas_axe_id')
            ->where('pas_axes.pas_id', $pasId)
            ->get([
                'pas_axes.code as axis_code',
                'pas_objectifs.code as objective_code',
                'pas_objectifs.id',
            ])
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->axis_code.'.'.(string) $row->objective_code => (int) $row->id,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function completedLegacyActionItems(): array
    {
        return [
            [
                'objectif_key' => 'AXE-I.OBJ-1',
                'direction_code' => 'DG',
                'service_code' => 'SCIQ',
                'responsable_email' => 'hilaire.nguebet@anbg.ga',
                'pao_titre' => 'PAO SCIQ gouvernance institutionnelle 2025',
                'pta_titre' => 'PTA SCIQ gouvernance institutionnelle 2025',
                'objectif_operationnel' => 'Finaliser le dispositif de gouvernance et de suivi qualité du cycle PAS 2020-2025.',
                'resultats_attendus' => 'Circuit de pilotage stabilisé, comptes rendus consolidés et responsabilités tracées.',
                'indicateurs_associes' => 'Taux de dispositifs de gouvernance clôturés',
                'libelle' => 'Clôturer le dispositif de gouvernance du PAS 2020-2025',
                'description' => 'Consolidation des circuits de gouvernance, des validations et des supports de pilotage du cycle stratégique 2020-2025.',
                'livrable' => 'Dossier de clôture gouvernance PAS 2020-2025',
                'date_debut' => '2025-01-13',
                'date_fin' => '2025-04-30',
                'date_fin_reelle' => '2025-04-24',
                'kpi_delai' => 96,
                'kpi_performance' => 94,
                'kpi_conformite' => 97,
                'kpi_qualite' => 95,
                'kpi_global' => 95,
                'evaluation_note' => 94,
                'direction_note' => 96,
                'financement_requis' => false,
            ],
            [
                'objectif_key' => 'AXE-I.OBJ-2',
                'direction_code' => 'DS',
                'service_code' => 'ENB',
                'responsable_email' => 'marie.simba@anbg.ga',
                'pao_titre' => 'PAO DS qualité de service 2025',
                'pta_titre' => 'PTA ENB qualité usagers 2025',
                'objectif_operationnel' => 'Améliorer la qualité du service rendu aux usagers et la traçabilité des demandes.',
                'resultats_attendus' => 'Demandes mieux suivies, délais maîtrisés et reporting de satisfaction produit.',
                'indicateurs_associes' => 'Taux de demandes traitées dans les délais',
                'libelle' => 'Finaliser le suivi qualité des demandes usagers',
                'description' => 'Traitement et clôture des actions qualité liées à l’accueil, au suivi et à la réponse aux usagers.',
                'livrable' => 'Rapport qualité usagers 2025',
                'date_debut' => '2025-02-03',
                'date_fin' => '2025-06-30',
                'date_fin_reelle' => '2025-07-08',
                'kpi_delai' => 82,
                'kpi_performance' => 89,
                'kpi_conformite' => 91,
                'kpi_qualite' => 88,
                'kpi_global' => 87,
                'evaluation_note' => 88,
                'direction_note' => 87,
                'financement_requis' => false,
            ],
            [
                'objectif_key' => 'AXE-II.OBJ-1',
                'direction_code' => 'DS',
                'service_code' => 'EB',
                'responsable_email' => 'codjo.menoueton@anbg.ga',
                'pao_titre' => 'PAO DS optimisation bourses 2025',
                'pta_titre' => 'PTA EB assainissement bourses 2025',
                'objectif_operationnel' => 'Assainir les données de bourses et sécuriser les contrôles de cohérence.',
                'resultats_attendus' => 'Dossiers consolidés, anomalies réduites et base de suivi fiabilisée.',
                'indicateurs_associes' => 'Taux de dossiers boursiers contrôlés',
                'libelle' => 'Assainir la base des dossiers boursiers',
                'description' => 'Contrôle des dossiers, rapprochement des données et clôture des anomalies identifiées dans le cycle 2020-2025.',
                'livrable' => 'Base des dossiers boursiers assainie',
                'date_debut' => '2025-01-20',
                'date_fin' => '2025-08-29',
                'date_fin_reelle' => '2025-08-22',
                'kpi_delai' => 95,
                'kpi_performance' => 92,
                'kpi_conformite' => 94,
                'kpi_qualite' => 91,
                'kpi_global' => 92,
                'evaluation_note' => 91,
                'direction_note' => 93,
                'financement_requis' => false,
            ],
            [
                'objectif_key' => 'AXE-II.OBJ-2',
                'direction_code' => 'DAF',
                'service_code' => 'SFC',
                'responsable_email' => 'melissa.abogo@anbg.ga',
                'pao_titre' => 'PAO DAF délais financiers 2025',
                'pta_titre' => 'PTA SFC délais de traitement 2025',
                'objectif_operationnel' => 'Réduire les délais de traitement financier liés aux dossiers de bourses.',
                'resultats_attendus' => 'Engagements rapprochés, délais réduits et état de clôture produit.',
                'indicateurs_associes' => 'Délai moyen de traitement financier',
                'libelle' => 'Clôturer le rapprochement des engagements financiers',
                'description' => 'Rapprochement des engagements, consolidation des états financiers et clôture des écarts constatés.',
                'livrable' => 'État de rapprochement financier 2025',
                'date_debut' => '2025-03-03',
                'date_fin' => '2025-09-30',
                'date_fin_reelle' => '2025-09-26',
                'kpi_delai' => 97,
                'kpi_performance' => 90,
                'kpi_conformite' => 93,
                'kpi_qualite' => 89,
                'kpi_global' => 91,
                'evaluation_note' => 90,
                'direction_note' => 92,
                'financement_requis' => true,
                'montant_estime' => 450000,
                'financement_reference' => 'DAF-PAS2025-SFC-001',
            ],
            [
                'objectif_key' => 'AXE-III.OBJ-1',
                'direction_code' => 'DSIC',
                'service_code' => 'SIRS',
                'responsable_email' => 'arnold.mindzeli@anbg.ga',
                'pao_titre' => 'PAO DSIC modernisation SI 2025',
                'pta_titre' => 'PTA SIRS applications métiers 2025',
                'objectif_operationnel' => 'Moderniser les outils de suivi et renforcer la disponibilité des applications métiers.',
                'resultats_attendus' => 'Outils stabilisés, disponibilité améliorée et incidents critiques réduits.',
                'indicateurs_associes' => 'Taux de disponibilité des applications métiers',
                'libelle' => 'Stabiliser les applications de suivi des bourses',
                'description' => 'Mise à niveau, tests de disponibilité et clôture des actions de stabilisation applicative.',
                'livrable' => 'Rapport de stabilisation applicative 2025',
                'date_debut' => '2025-02-10',
                'date_fin' => '2025-10-31',
                'date_fin_reelle' => '2025-10-20',
                'kpi_delai' => 98,
                'kpi_performance' => 93,
                'kpi_conformite' => 92,
                'kpi_qualite' => 94,
                'kpi_global' => 93,
                'evaluation_note' => 92,
                'direction_note' => 94,
                'financement_requis' => true,
                'montant_estime' => 850000,
                'financement_reference' => 'DSIC-PAS2025-SIRS-001',
            ],
            [
                'objectif_key' => 'AXE-III.OBJ-2',
                'direction_code' => 'DSIC',
                'service_code' => 'GDS',
                'responsable_email' => 'staelle.komba@anbg.ga',
                'pao_titre' => 'PAO DSIC fiabilisation données 2025',
                'pta_titre' => 'PTA GDS données et statistiques 2025',
                'objectif_operationnel' => 'Fiabiliser les données statistiques et documentaires relatives aux boursiers.',
                'resultats_attendus' => 'Référentiels consolidés, statistiques disponibles et documents mieux classés.',
                'indicateurs_associes' => 'Taux de fiabilisation des données',
                'libelle' => 'Fiabiliser les statistiques des boursiers',
                'description' => 'Nettoyage, rapprochement et consolidation des données statistiques pour clôturer le cycle 2020-2025.',
                'livrable' => 'Référentiel statistique consolidé 2025',
                'date_debut' => '2025-04-01',
                'date_fin' => '2025-11-28',
                'date_fin_reelle' => '2025-11-28',
                'kpi_delai' => 94,
                'kpi_performance' => 91,
                'kpi_conformite' => 96,
                'kpi_qualite' => 95,
                'kpi_global' => 93,
                'evaluation_note' => 94,
                'direction_note' => 95,
                'financement_requis' => false,
            ],
            [
                'objectif_key' => 'AXE-IV.OBJ-1',
                'direction_code' => 'DG',
                'service_code' => 'CAB',
                'responsable_email' => 'loick.adan@anbg.ga',
                'pao_titre' => 'PAO Cabinet partenariats 2025',
                'pta_titre' => 'PTA Cabinet conventions 2025',
                'objectif_operationnel' => 'Consolider les partenariats institutionnels utiles au dispositif de bourses.',
                'resultats_attendus' => 'Conventions suivies, échéances maîtrisées et opportunités documentées.',
                'indicateurs_associes' => 'Nombre de conventions actives suivies',
                'libelle' => 'Consolider les conventions partenaires',
                'description' => 'Revue des conventions, consolidation des engagements et clôture des livrables partenaires du cycle 2020-2025.',
                'livrable' => 'Tableau de suivi des conventions partenaires',
                'date_debut' => '2025-03-17',
                'date_fin' => '2025-10-15',
                'date_fin_reelle' => '2025-10-10',
                'kpi_delai' => 96,
                'kpi_performance' => 88,
                'kpi_conformite' => 90,
                'kpi_qualite' => 89,
                'kpi_global' => 90,
                'evaluation_note' => 90,
                'direction_note' => 91,
                'financement_requis' => false,
            ],
            [
                'objectif_key' => 'AXE-IV.OBJ-2',
                'direction_code' => 'DAF',
                'service_code' => 'SFC',
                'responsable_email' => 'audrey.mouloungui@anbg.ga',
                'pao_titre' => 'PAO DAF soutenabilité financière 2025',
                'pta_titre' => 'PTA SFC soutenabilité financière 2025',
                'objectif_operationnel' => 'Consolider les éléments de soutenabilité financière du dispositif de bourses.',
                'resultats_attendus' => 'Rapport financier finalisé, arbitrages documentés et données budgétaires consolidées.',
                'indicateurs_associes' => 'Taux de consolidation du rapport financier',
                'libelle' => 'Produire le rapport de soutenabilité financière',
                'description' => 'Préparation du rapport de soutenabilité financière, justification des écarts et clôture du cycle budgétaire 2020-2025.',
                'livrable' => 'Rapport de soutenabilité financière PAS 2020-2025',
                'date_debut' => '2025-05-05',
                'date_fin' => '2025-12-12',
                'date_fin_reelle' => '2025-12-18',
                'kpi_delai' => 80,
                'kpi_performance' => 92,
                'kpi_conformite' => 93,
                'kpi_qualite' => 90,
                'kpi_global' => 88,
                'kpi_alert_threshold' => 90,
                'evaluation_note' => 91,
                'direction_note' => 89,
                'financement_requis' => true,
                'montant_estime' => 620000,
                'financement_reference' => 'DAF-PAS2025-SFC-002',
            ],
        ];
    }

    /**
     * @return array{direction_id:int, service_id:int}|null
     */
    private function resolveDirectionService(string $directionCode, string $serviceCode): ?array
    {
        $row = DB::table('services')
            ->join('directions', 'directions.id', '=', 'services.direction_id')
            ->where('directions.code', $directionCode)
            ->where('services.code', $serviceCode)
            ->first(['directions.id as direction_id', 'services.id as service_id']);

        if ($row === null) {
            return null;
        }

        return [
            'direction_id' => (int) $row->direction_id,
            'service_id' => (int) $row->service_id,
        ];
    }

    private function resolveResponsableId(?string $email, int $directionId, int $serviceId): ?int
    {
        if ($email !== null) {
            $query = DB::table('users')->where('email', strtolower($email));
            if (Schema::hasColumn('users', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            $id = $query->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        $query = DB::table('users')
            ->where('direction_id', $directionId)
            ->where('service_id', $serviceId)
            ->orderByRaw("CASE WHEN role = '".User::ROLE_AGENT."' THEN 0 WHEN role = '".User::ROLE_SERVICE."' THEN 1 ELSE 2 END")
            ->orderBy('id');

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $id = $query->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function resolveRoleUserId(string $role, int $directionId, ?int $serviceId = null): ?int
    {
        $query = DB::table('users')
            ->where('direction_id', $directionId)
            ->where('role', $role)
            ->orderBy('id');

        if ($serviceId !== null) {
            $query->where('service_id', $serviceId);
        }

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $id = $query->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function resolveDirectionValidatorId(int $directionId): ?int
    {
        return $this->resolveRoleUserId(User::ROLE_DIRECTION, $directionId)
            ?? $this->resolveRoleUserId(User::ROLE_DG, $directionId);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function upsertLegacyPao(
        int $pasId,
        int $objectiveId,
        int $directionId,
        int $serviceId,
        ?int $exerciseId,
        array $item,
        ?int $validatorId,
        mixed $now
    ): int {
        $payload = $this->filterColumns('paos', [
            'exercice_id' => $exerciseId,
            'pas_id' => $pasId,
            'pas_objectif_id' => $objectiveId,
            'direction_id' => $directionId,
            'service_id' => $serviceId,
            'annee' => 2025,
            'titre' => (string) $item['pao_titre'],
            'echeance' => '2025-12-31',
            'objectif_operationnel' => (string) $item['objectif_operationnel'],
            'resultats_attendus' => (string) $item['resultats_attendus'],
            'indicateurs_associes' => (string) $item['indicateurs_associes'],
            'statut' => 'verrouille',
            'valide_le' => $now,
            'valide_par' => $validatorId,
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $paoId = DB::table('paos')
            ->where('pas_objectif_id', $objectiveId)
            ->where('annee', 2025)
            ->where('direction_id', $directionId)
            ->where('service_id', $serviceId)
            ->value('id');

        if ($paoId !== null) {
            DB::table('paos')->where('id', (int) $paoId)->update($payload);

            return (int) $paoId;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('paos')->insertGetId($payload);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function upsertLegacyPta(
        int $paoId,
        int $directionId,
        int $serviceId,
        ?int $exerciseId,
        array $item,
        ?int $validatorId,
        mixed $now
    ): int {
        $payload = $this->filterColumns('ptas', [
            'exercice_id' => $exerciseId,
            'pao_id' => $paoId,
            'direction_id' => $directionId,
            'service_id' => $serviceId,
            'titre' => (string) $item['pta_titre'],
            'description' => 'PTA historique clôturé pour le PAS 2020-2025.',
            'statut' => 'verrouille',
            'valide_le' => $now,
            'valide_par' => $validatorId,
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $ptaId = DB::table('ptas')->where('pao_id', $paoId)->value('id');

        if ($ptaId !== null) {
            DB::table('ptas')->where('id', (int) $ptaId)->update($payload);

            return (int) $ptaId;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('ptas')->insertGetId($payload);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function upsertCompletedLegacyAction(
        int $ptaId,
        ?int $exerciseId,
        array $item,
        ?int $responsableId,
        ?int $chefId,
        ?int $validatorId,
        mixed $now
    ): int {
        $actualEnd = Carbon::parse((string) $item['date_fin_reelle']);
        $plannedEnd = Carbon::parse((string) $item['date_fin']);
        $dynamicStatus = $actualEnd->lte($plannedEnd) ? 'acheve_dans_delai' : 'acheve_hors_delai';
        $financingRequired = (bool) ($item['financement_requis'] ?? false);

        $payload = $this->filterColumns('actions', [
            'exercice_id' => $exerciseId,
            'pta_id' => $ptaId,
            'mode_evaluation' => Action::MODE_MIXTE,
            'libelle' => (string) $item['libelle'],
            'description' => (string) $item['description'],
            'type_cible' => 'quantitative',
            'unite_cible' => '%',
            'quantite_cible' => 100,
            'quantite_realisee' => 100,
            'resultat_attendu' => (string) $item['resultats_attendus'],
            'criteres_validation' => 'Livrable disponible, validation hiérarchique réalisée et indicateurs consolidés.',
            'livrable_attendu' => (string) $item['livrable'],
            'date_debut' => (string) $item['date_debut'],
            'date_fin' => (string) $item['date_fin'],
            'date_fin_reelle' => (string) $item['date_fin_reelle'],
            'date_echeance' => (string) $item['date_fin'],
            'responsable_id' => $responsableId,
            'statut' => 'termine',
            'statut_dynamique' => $dynamicStatus,
            'progression_reelle' => 100,
            'progression_theorique' => 100,
            'avancement_operationnel' => 100,
            'taux_atteinte_cible' => 100,
            'taux_global' => (float) $item['kpi_global'],
            'seuil_alerte_progression' => 10,
            'risques' => 'Risque résiduel faible après clôture.',
            'mesures_preventives' => 'Contrôle final, archivage des livrables et validation hiérarchique.',
            'financement_requis' => $financingRequired,
            'description_financement' => $financingRequired ? 'Financement nécessaire au traitement et à la clôture de l’action.' : null,
            'source_financement' => $financingRequired ? 'Budget ANBG' : null,
            'montant_estime' => $financingRequired ? (float) ($item['montant_estime'] ?? 0) : null,
            'financement_statut' => $financingRequired ? 'accorde_dg' : 'non_requis',
            'financement_soumis_le' => $financingRequired ? $actualEnd->copy()->subDays(18) : null,
            'financement_notifie_le' => $financingRequired ? $actualEnd->copy()->subDays(17) : null,
            'financement_daf_par' => $financingRequired ? $this->resolveDafValidatorId() : null,
            'financement_daf_le' => $financingRequired ? $actualEnd->copy()->subDays(12) : null,
            'financement_daf_decision' => $financingRequired ? 'Avis favorable DAF' : null,
            'financement_daf_commentaire' => $financingRequired ? 'Financement conforme au besoin validé.' : null,
            'financement_montant_valide' => $financingRequired ? (float) ($item['montant_estime'] ?? 0) : null,
            'financement_reference' => $financingRequired ? (string) ($item['financement_reference'] ?? 'PAS2025-FIN') : null,
            'financement_dg_par' => $financingRequired ? $this->resolveDgValidatorId() : null,
            'financement_dg_le' => $financingRequired ? $actualEnd->copy()->subDays(8) : null,
            'financement_dg_decision' => $financingRequired ? 'Accord DG' : null,
            'financement_dg_commentaire' => $financingRequired ? 'Accord donné pour clôture du cycle PAS 2020-2025.' : null,
            'ressource_main_oeuvre' => true,
            'ressource_equipement' => $financingRequired,
            'ressource_partenariat' => false,
            'ressource_autres' => false,
            'ressource_autres_details' => null,
            'rapport_final' => 'Action historique clôturée dans le cadre du PAS ANBG 2020-2025.',
            'validation_hierarchique' => true,
            'validation_sans_correction' => true,
            'statut_validation' => 'validee_direction',
            'soumise_par' => $responsableId,
            'soumise_le' => $actualEnd->copy()->subDays(7),
            'evalue_par' => $chefId,
            'evalue_le' => $actualEnd->copy()->subDays(5),
            'evaluation_note' => (float) $item['evaluation_note'],
            'evaluation_commentaire' => 'Clôture validée au niveau service.',
            'direction_valide_par' => $validatorId,
            'direction_valide_le' => $actualEnd,
            'direction_evaluation_note' => (float) $item['direction_note'],
            'direction_evaluation_commentaire' => 'Action intégrée aux données historiques du PAS 2020-2025.',
            'frequence_execution' => 'hebdomadaire',
            'deleted_at' => null,
            'updated_at' => $now,
        ]);

        $actionId = DB::table('actions')
            ->where('pta_id', $ptaId)
            ->where('libelle', (string) $item['libelle'])
            ->value('id');

        if ($actionId !== null) {
            DB::table('actions')->where('id', (int) $actionId)->update($payload);

            return (int) $actionId;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('actions')->insertGetId($payload);
    }

    private function resolveDafValidatorId(): ?int
    {
        $directionId = DB::table('directions')->where('code', 'DAF')->value('id');

        return $directionId !== null ? $this->resolveRoleUserId(User::ROLE_DIRECTION, (int) $directionId) : null;
    }

    private function resolveDgValidatorId(): ?int
    {
        $directionId = DB::table('directions')->where('code', 'DG')->value('id');

        return $directionId !== null ? $this->resolveRoleUserId(User::ROLE_DG, (int) $directionId) : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function syncLegacyActionWeeks(int $actionId, array $item, ?int $responsableId, mixed $now): void
    {
        if (! Schema::hasTable('action_weeks')) {
            return;
        }

        $start = Carbon::parse((string) $item['date_debut']);

        foreach ([25, 50, 75, 100] as $index => $progression) {
            $weekNumber = $index + 1;
            $weekStart = $start->copy()->addWeeks($index * 3);
            $weekEnd = $weekStart->copy()->addDays(6);

            DB::table('action_weeks')->updateOrInsert(
                [
                    'action_id' => $actionId,
                    'numero_semaine' => $weekNumber,
                ],
                [
                    'date_debut' => $weekStart->toDateString(),
                    'date_fin' => $weekEnd->toDateString(),
                    'est_renseignee' => true,
                    'quantite_realisee' => 25,
                    'quantite_cumulee' => $progression,
                    'taches_realisees' => 'Jalon historique '.$weekNumber.' renseigné pour la clôture du PAS 2020-2025.',
                    'avancement_estime' => $progression,
                    'commentaire' => 'Donnée historique importée par le seeder institutionnel.',
                    'difficultes' => null,
                    'mesures_correctives' => 'Suivi finalisé et archivé.',
                    'progression_reelle' => $progression,
                    'progression_theorique' => min(100, $progression),
                    'ecart_progression' => 0,
                    'saisi_par' => $responsableId,
                    'saisi_le' => $weekEnd->copy()->setTime(17, 0),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function syncLegacyActionKpis(int $actionId, ?int $exerciseId, array $item, ?int $responsableId, mixed $now): void
    {
        if (Schema::hasTable('action_kpis')) {
            DB::table('action_kpis')->updateOrInsert(
                ['action_id' => $actionId],
                $this->filterColumns('action_kpis', [
                    'action_id' => $actionId,
                    'kpi_delai' => (float) $item['kpi_delai'],
                    'kpi_performance' => (float) $item['kpi_performance'],
                    'kpi_conformite' => (float) $item['kpi_conformite'],
                    'kpi_qualite' => (float) $item['kpi_qualite'],
                    'kpi_risque' => 0.0,
                    'kpi_global' => (float) $item['kpi_global'],
                    'progression_reelle' => 100,
                    'progression_theorique' => 100,
                    'statut_calcule' => Carbon::parse((string) $item['date_fin_reelle'])->lte(Carbon::parse((string) $item['date_fin']))
                        ? 'acheve_dans_delai'
                        : 'acheve_hors_delai',
                    'derniere_evaluation_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }

        if (! Schema::hasTable('kpis')) {
            return;
        }

        $kpiPayload = $this->filterColumns('kpis', [
            'exercice_id' => $exerciseId,
            'action_id' => $actionId,
            'libelle' => 'Indicateur global action cloturee',
            'unite' => 'points',
            'cible' => 100,
            'seuil_alerte' => (float) ($item['kpi_alert_threshold'] ?? 75),
            'periodicite' => 'annuel',
            'est_a_renseigner' => false,
            'updated_at' => $now,
        ]);

        $kpiId = DB::table('kpis')
            ->where('action_id', $actionId)
            ->where('libelle', 'Indicateur global action cloturee')
            ->value('id');

        if ($kpiId !== null) {
            DB::table('kpis')->where('id', (int) $kpiId)->update($kpiPayload);
            $kpiId = (int) $kpiId;
        } else {
            $kpiPayload['created_at'] = $now;
            $kpiId = (int) DB::table('kpis')->insertGetId($kpiPayload);
        }

        if (Schema::hasTable('kpi_mesures')) {
            DB::table('kpi_mesures')->updateOrInsert(
                [
                    'kpi_id' => $kpiId,
                    'periode' => '2025-12',
                ],
                [
                    'valeur' => (float) $item['kpi_global'],
                    'commentaire' => 'Mesure de clôture historique PAS 2020-2025.',
                    'saisi_par' => $responsableId,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function syncLegacyActionLogs(
        int $actionId,
        array $item,
        ?int $responsableId,
        ?int $chefId,
        ?int $validatorId,
        mixed $now
    ): void {
        if (! Schema::hasTable('action_logs')) {
            return;
        }

        $logs = [
            [
                'type_evenement' => 'suivi_hebdomadaire',
                'niveau' => 'info',
                'message' => 'Progression historique consolidée à 100 % pour le PAS 2020-2025.',
                'utilisateur_id' => $responsableId,
                'cible_role' => User::ROLE_SERVICE,
            ],
            [
                'type_evenement' => 'evaluation_service',
                'niveau' => 'info',
                'message' => 'Validation service enregistrée pour l’action clôturée.',
                'utilisateur_id' => $chefId,
                'cible_role' => User::ROLE_AGENT,
            ],
            [
                'type_evenement' => 'validation_direction',
                'niveau' => 'info',
                'message' => 'Validation direction enregistrée pour l’intégration historique.',
                'utilisateur_id' => $validatorId,
                'cible_role' => User::ROLE_DIRECTION,
            ],
        ];

        if (Carbon::parse((string) $item['date_fin_reelle'])->gt(Carbon::parse((string) $item['date_fin']))) {
            $logs[] = [
                'type_evenement' => 'retard_cloture_historique',
                'niveau' => 'warning',
                'message' => 'Action historique clôturée hors délai sur le PAS 2020-2025.',
                'utilisateur_id' => $responsableId,
                'cible_role' => User::ROLE_SERVICE,
            ];
        }
        foreach ($logs as $log) {
            DB::table('action_logs')->updateOrInsert(
                [
                    'action_id' => $actionId,
                    'type_evenement' => $log['type_evenement'],
                    'message' => $log['message'],
                ],
                [
                    'action_week_id' => null,
                    'niveau' => $log['niveau'],
                    'details' => json_encode([
                        'source' => 'PAS 2020-2025',
                        'kpi_global' => (float) $item['kpi_global'],
                    ], JSON_UNESCAPED_UNICODE),
                    'cible_role' => $log['cible_role'],
                    'utilisateur_id' => $log['utilisateur_id'],
                    'lu' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function filterColumns(string $table, array $payload): array
    {
        return array_filter(
            $payload,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }
}
