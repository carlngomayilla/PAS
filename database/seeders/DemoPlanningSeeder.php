<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DemoPlanningSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $targetYear = 2026;

        $anchorUsers = DB::table('users')
            ->whereIn('email', ['robert.ekomi@anbg.ga', 'arnold.mindzeli@anbg.ga', 'marie.simba@anbg.ga'])
            ->get(['id', 'email', 'direction_id', 'service_id']);

        if ($anchorUsers->count() < 3) {
            return;
        }

        $directionRows = DB::table('directions')
            ->whereIn('id', $anchorUsers->pluck('direction_id')->filter()->all())
            ->orderBy('code')
            ->get(['id', 'code', 'libelle']);

        if ($directionRows->count() < 3) {
            return;
        }

        $directionMap = $directionRows->keyBy('code');
        $serviceRows = $this->resolveServiceRows($anchorUsers, $directionRows);
        if (count($serviceRows) < 3) {
            return;
        }

        $anchorUsersByEmail = $anchorUsers->keyBy('email');
        $preferredResponsables = $anchorUsers
            ->filter(static fn ($user): bool => $user->service_id !== null)
            ->mapWithKeys(static fn ($user): array => [(int) $user->service_id => (int) $user->id])
            ->all();
        $serviceIds = array_map(static fn (array $row): int => (int) $row['id'], $serviceRows);
        $responsables = $this->resolveResponsables($serviceIds, $preferredResponsables);
        $chefsService = $this->resolveChefsService($serviceIds);
        $directeurs = $this->resolveDirecteurs($directionRows->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $adminOrDgId = (int) (DB::table('users')
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION])
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 WHEN role = 'dg' THEN 1 ELSE 2 END")
            ->value('id') ?? 0);
        $financeAnchor = $anchorUsersByEmail->get('robert.ekomi@anbg.ga');

        $pasId = (int) DB::table('pas')->insertGetId([
            'titre' => 'PAS ANBG 2026-2028',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'valide',
            'created_by' => $adminOrDgId > 0 ? $adminOrDgId : null,
            'valide_le' => $now,
            'valide_par' => $adminOrDgId > 0 ? $adminOrDgId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($directionRows as $direction) {
            DB::table('pas_directions')->insert([
                'pas_id' => $pasId,
                'direction_id' => (int) $direction->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $patterns = $this->actionPatterns();
        $patternIndex = 0;
        $blueprint = $this->planningBlueprint();

        foreach ($blueprint as $axeIndex => $axe) {
            $axeId = (int) DB::table('pas_axes')->insertGetId([
                'pas_id' => $pasId,
                'direction_id' => null,
                'code' => $axe['code'],
                'libelle' => $axe['libelle'],
                'periode_debut' => '2026-01-01',
                'periode_fin' => '2028-12-31',
                'description' => $axe['description'],
                'ordre' => $axeIndex + 1,
                'created_by' => $adminOrDgId > 0 ? $adminOrDgId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($axe['objectifs'] as $objectifIndex => $objectif) {
                $objectifId = (int) DB::table('pas_objectifs')->insertGetId([
                    'pas_axe_id' => $axeId,
                    'code' => $objectif['code'],
                    'libelle' => $objectif['libelle'],
                    'description' => $objectif['description'],
                    'ordre' => $objectifIndex + 1,
                    'indicateur_global' => $objectif['indicateur_global'],
                    'valeur_cible' => $objectif['valeur_cible'],
                    'valeurs_cible' => json_encode([
                        'indicateur_global' => $objectif['indicateur_global'],
                        'valeur_cible' => $objectif['valeur_cible'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_by' => $adminOrDgId > 0 ? $adminOrDgId : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($directionRows as $direction) {
                    $directionCode = (string) $direction->code;
                    $directionId = (int) $direction->id;
                    $service = $serviceRows[$directionCode] ?? null;

                    if (! is_array($service)) {
                        continue;
                    }

                    $pattern = $patterns[$patternIndex % count($patterns)];
                    $patternIndex++;

                    if (
                        $financeAnchor !== null
                        && (int) $financeAnchor->direction_id === $directionId
                        && (int) $financeAnchor->service_id === (int) $service['id']
                        && $objectif['code'] === 'OS-1.1'
                    ) {
                        $pattern['pao_statut'] = 'soumis';
                        $pattern['pta_statut'] = 'soumis';
                        $pattern['type_cible'] = 'quantitative';
                        $pattern['statut'] = 'en_cours';
                        $pattern['statut_dynamique'] = 'en_cours';
                        $pattern['statut_validation'] = 'non_soumise';
                        $pattern['weeks_filled'] = 0;
                    }

                    $paoId = (int) DB::table('paos')->insertGetId([
                        'pas_id' => $pasId,
                        'pas_objectif_id' => $objectifId,
                        'direction_id' => $directionId,
                        'service_id' => (int) $service['id'],
                        'annee' => $targetYear,
                        'titre' => sprintf('PAO %s %s %d', $directionCode, $objectif['code'], $targetYear),
                        'echeance' => $targetYear.'-12-31',
                        'objectif_operationnel' => sprintf(
                            'Decliner %s pour la %s.',
                            $objectif['libelle'],
                            $direction->libelle
                        ),
                        'resultats_attendus' => sprintf(
                            'Livrer les resultats operationnels de %s dans le perimetre %s.',
                            $objectif['code'],
                            $directionCode
                        ),
                        'indicateurs_associes' => $objectif['indicateur_global'],
                        'statut' => $pattern['pao_statut'],
                        'valide_le' => $pattern['pao_statut'] === 'valide' ? $now : null,
                        'valide_par' => $pattern['pao_statut'] === 'valide' && ($directeurs[$directionId] ?? null) !== null
                            ? $directeurs[$directionId]
                            : ($adminOrDgId > 0 ? $adminOrDgId : null),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $ptaId = (int) DB::table('ptas')->insertGetId([
                        'pao_id' => $paoId,
                        'direction_id' => $directionId,
                        'service_id' => (int) $service['id'],
                        'titre' => sprintf('PTA %s %s %d', $service['code'], $objectif['code'], $targetYear),
                        'description' => sprintf(
                            'Declinaison service de l objectif strategique %s pour %s.',
                            $objectif['code'],
                            $service['libelle']
                        ),
                        'statut' => $pattern['pta_statut'],
                        'valide_le' => $pattern['pta_statut'] === 'valide' ? $now : null,
                        'valide_par' => $pattern['pta_statut'] === 'valide'
                            ? (($chefsService[(int) $service['id']] ?? null) ?: ($adminOrDgId > 0 ? $adminOrDgId : null))
                            : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $responsableId = $responsables[(int) $service['id']] ?? null;
                    $chefId = $chefsService[(int) $service['id']] ?? null;
                    $directeurId = $directeurs[$directionId] ?? null;
                    $dates = $this->buildActionDates($patternIndex);

                    $actionLibelle = sprintf('%s - %s', $objectif['action_base'], $directionCode);
                    if (
                        $financeAnchor !== null
                        && (int) $financeAnchor->direction_id === $directionId
                        && (int) $financeAnchor->service_id === (int) $service['id']
                        && $objectif['code'] === 'OS-1.1'
                    ) {
                        $actionLibelle = 'Automatiser le suivi des engagements';
                    }

                    $actionId = (int) DB::table('actions')->insertGetId([
                        'pta_id' => $ptaId,
                        'libelle' => $actionLibelle,
                        'description' => sprintf(
                            'Action de demonstration rattachee a %s / %s / %s.',
                            $axe['code'],
                            $objectif['code'],
                            $directionCode
                        ),
                        'type_cible' => $pattern['type_cible'],
                        'unite_cible' => $pattern['type_cible'] === 'quantitative' ? 'taches' : null,
                        'quantite_cible' => $pattern['type_cible'] === 'quantitative' ? 100 : null,
                        'resultat_attendu' => $pattern['type_cible'] === 'qualitative'
                            ? sprintf('Resultat attendu pour %s dans la direction %s.', $objectif['code'], $directionCode)
                            : null,
                        'criteres_validation' => 'Conformite des livrables, respect des delais et tracabilite.',
                        'livrable_attendu' => sprintf('Livrable %s %s', $objectif['code'], $directionCode),
                        'date_debut' => $dates['start'],
                        'date_fin' => $dates['planned_end'],
                        'date_echeance' => $dates['planned_end'],
                        'date_fin_reelle' => $pattern['statut_dynamique'] === 'acheve_dans_delai' ? $dates['actual_end'] : null,
                        'responsable_id' => $responsableId,
                        'statut' => $pattern['statut'],
                        'statut_dynamique' => $pattern['statut_dynamique'],
                        'progression_reelle' => $pattern['progression_reelle'],
                        'progression_theorique' => $pattern['progression_theorique'],
                        'seuil_alerte_progression' => 10,
                        'risques' => 'Charge de travail et disponibilite des contributeurs.',
                        'mesures_preventives' => 'Revues hebdomadaires et arbitrage rapide.',
                        'financement_requis' => $patternIndex % 3 === 0,
                        'description_financement' => $patternIndex % 3 === 0 ? 'Budget de fonctionnement et appui logistique.' : null,
                        'source_financement' => $patternIndex % 3 === 0 ? 'Budget interne' : null,
                        'montant_estime' => $patternIndex % 3 === 0 ? 150000 + ($patternIndex * 2500) : null,
                        'ressource_main_oeuvre' => true,
                        'ressource_equipement' => $directionCode === 'DSIC',
                        'ressource_partenariat' => $directionCode !== 'DSIC',
                        'ressource_autres' => false,
                        'ressource_autres_details' => null,
                        'rapport_final' => $pattern['statut_dynamique'] === 'acheve_dans_delai'
                            ? 'Rapport final de demonstration valide.'
                            : null,
                        'validation_hierarchique' => in_array($pattern['statut_validation'], ['validee_chef', 'validee_direction'], true),
                        'validation_sans_correction' => $pattern['statut_validation'] === 'validee_direction' ? true : null,
                        'statut_validation' => $pattern['statut_validation'],
                        'soumise_par' => $pattern['statut_validation'] !== 'non_soumise' ? $responsableId : null,
                        'soumise_le' => $pattern['statut_validation'] !== 'non_soumise' ? Carbon::parse($dates['start'])->addWeeks(2) : null,
                        'evalue_par' => in_array($pattern['statut_validation'], ['rejetee_chef', 'validee_chef', 'validee_direction', 'rejetee_direction'], true) ? $chefId : null,
                        'evalue_le' => in_array($pattern['statut_validation'], ['rejetee_chef', 'validee_chef', 'validee_direction', 'rejetee_direction'], true) ? Carbon::parse($dates['start'])->addWeeks(3) : null,
                        'evaluation_note' => in_array($pattern['statut_validation'], ['rejetee_chef', 'validee_chef', 'validee_direction'], true) ? $pattern['chef_note'] : null,
                        'evaluation_commentaire' => in_array($pattern['statut_validation'], ['rejetee_chef', 'validee_chef', 'validee_direction'], true) ? $pattern['chef_commentaire'] : null,
                        'direction_valide_par' => in_array($pattern['statut_validation'], ['rejetee_direction', 'validee_direction'], true) ? $directeurId : null,
                        'direction_valide_le' => in_array($pattern['statut_validation'], ['rejetee_direction', 'validee_direction'], true) ? Carbon::parse($dates['start'])->addWeeks(4) : null,
                        'direction_evaluation_note' => in_array($pattern['statut_validation'], ['rejetee_direction', 'validee_direction'], true) ? $pattern['direction_note'] : null,
                        'direction_evaluation_commentaire' => in_array($pattern['statut_validation'], ['rejetee_direction', 'validee_direction'], true) ? $pattern['direction_commentaire'] : null,
                        'frequence_execution' => $pattern['frequence_execution'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $weeks = $this->createActionWeeks($actionId, $responsableId, $dates['start'], $pattern);
                    $this->createActionKpis($actionId, $pattern, $now);
                    $this->createKpiMeasure($actionId, $responsableId, $pattern, $targetYear, $now);
                    $this->createActionLogs($actionId, $weeks, $responsableId, $chefId, $directeurId, $pattern, $now);
                }
            }
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $anchorUsers
     * @param \Illuminate\Support\Collection<int, object> $directionRows
     * @return array<string, array{id:int,code:string,libelle:string}>
     */
    private function resolveServiceRows($anchorUsers, $directionRows): array
    {
        $directionCodeMap = $directionRows->mapWithKeys(static fn ($direction): array => [
            (int) $direction->id => (string) $direction->code,
        ]);
        $resolved = [];
        foreach ($anchorUsers as $user) {
            $directionId = (int) $user->direction_id;
            $serviceId = (int) $user->service_id;
            $directionCode = (string) ($directionCodeMap[$directionId] ?? '');

            if ($directionCode === '' || $serviceId <= 0) {
                continue;
            }

            $service = DB::table('services')
                ->where('id', $serviceId)
                ->first(['id', 'code', 'libelle']);

            if ($service !== null) {
                $resolved[$directionCode] = [
                    'id' => (int) $service->id,
                    'code' => (string) $service->code,
                    'libelle' => (string) $service->libelle,
                ];
            }
        }

        return $resolved;
    }

    /**
     * @param array<int, int> $serviceIds
     * @param array<int, int> $preferredResponsables
     * @return array<int, int>
     */
    private function resolveResponsables(array $serviceIds, array $preferredResponsables = []): array
    {
        $rows = DB::table('users')
            ->whereIn('service_id', $serviceIds)
            ->orderByRaw("CASE WHEN role = 'agent' THEN 0 WHEN is_agent = 1 THEN 1 WHEN role = 'service' THEN 2 ELSE 3 END")
            ->orderBy('id')
            ->get(['id', 'service_id']);

        $resolved = $preferredResponsables;
        foreach ($rows as $row) {
            $serviceId = (int) $row->service_id;
            if (! array_key_exists($serviceId, $resolved)) {
                $resolved[$serviceId] = (int) $row->id;
            }
        }

        return $resolved;
    }

    /**
     * @param array<int, int> $serviceIds
     * @return array<int, int>
     */
    private function resolveChefsService(array $serviceIds): array
    {
        $rows = DB::table('users')
            ->whereIn('service_id', $serviceIds)
            ->where('role', User::ROLE_SERVICE)
            ->orderBy('id')
            ->get(['id', 'service_id']);

        return $rows
            ->mapWithKeys(static fn ($row): array => [(int) $row->service_id => (int) $row->id])
            ->all();
    }

    /**
     * @param array<int, int> $directionIds
     * @return array<int, int>
     */
    private function resolveDirecteurs(array $directionIds): array
    {
        $rows = DB::table('users')
            ->whereIn('direction_id', $directionIds)
            ->where('role', User::ROLE_DIRECTION)
            ->orderBy('id')
            ->get(['id', 'direction_id']);

        return $rows
            ->mapWithKeys(static fn ($row): array => [(int) $row->direction_id => (int) $row->id])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function planningBlueprint(): array
    {
        return [
            [
                'code' => 'AXE-1',
                'libelle' => 'Qualite de service et performance',
                'description' => 'Renforcer la qualite de service, la tracabilite et la reactivite des processus.',
                'objectifs' => [
                    [
                        'code' => 'OS-1.1',
                        'libelle' => 'Fluidifier le traitement des dossiers',
                        'description' => 'Reduire les delais de traitement et mieux suivre les dossiers prioritaires.',
                        'indicateur_global' => 'Taux de dossiers traites dans le delai cible',
                        'valeur_cible' => '90%',
                        'action_base' => 'Optimiser le suivi des dossiers prioritaires',
                    ],
                    [
                        'code' => 'OS-1.2',
                        'libelle' => 'Standardiser le reporting mensuel',
                        'description' => 'Harmoniser les formats et le rythme de reporting par direction.',
                        'indicateur_global' => 'Taux de reporting mensuel produit a temps',
                        'valeur_cible' => '100%',
                        'action_base' => 'Produire un reporting mensuel harmonise',
                    ],
                ],
            ],
            [
                'code' => 'AXE-2',
                'libelle' => 'Transformation numerique',
                'description' => 'Appuyer la digitalisation et la securisation des processus metier.',
                'objectifs' => [
                    [
                        'code' => 'OS-2.1',
                        'libelle' => 'Securiser les applications et donnees',
                        'description' => 'Ameliorer la protection des donnees critiques et la disponibilite des services.',
                        'indicateur_global' => 'Niveau de disponibilite et de securite des services numeriques',
                        'valeur_cible' => '99%',
                        'action_base' => 'Renforcer la securite et la disponibilite des services',
                    ],
                    [
                        'code' => 'OS-2.2',
                        'libelle' => 'Dematerialiser les flux de travail',
                        'description' => 'Digitaliser les processus de suivi et de validation.',
                        'indicateur_global' => 'Taux de processus dematerialises',
                        'valeur_cible' => '75%',
                        'action_base' => 'Dematerialiser les circuits de validation',
                    ],
                ],
            ],
            [
                'code' => 'AXE-3',
                'libelle' => 'Pilotage, conformite et controle',
                'description' => 'Consolider le pilotage strategique, le controle et la conformite documentaire.',
                'objectifs' => [
                    [
                        'code' => 'OS-3.1',
                        'libelle' => 'Ameliorer la conformite documentaire',
                        'description' => 'Renforcer la qualite et la disponibilite des pieces justificatives.',
                        'indicateur_global' => 'Taux de conformite documentaire',
                        'valeur_cible' => '95%',
                        'action_base' => 'Structurer la conformite documentaire',
                    ],
                    [
                        'code' => 'OS-3.2',
                        'libelle' => 'Consolider le suivi PAS / PAO / PTA',
                        'description' => 'Rendre visible et fiable la chaine de pilotage jusqu aux actions.',
                        'indicateur_global' => 'Taux de couverture de la chaine de pilotage',
                        'valeur_cible' => '100%',
                        'action_base' => 'Consolider le suivi de la chaine de pilotage',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function actionPatterns(): array
    {
        return [
            [
                'type_cible' => 'qualitative',
                'statut' => 'non_demarre',
                'statut_dynamique' => 'non_demarre',
                'statut_validation' => 'non_soumise',
                'progression_reelle' => 0,
                'progression_theorique' => 12,
                'kpi_delai' => 0,
                'kpi_performance' => 0,
                'kpi_conformite' => 0,
                'kpi_global' => 0,
                'weeks_filled' => 0,
                'pao_statut' => 'brouillon',
                'pta_statut' => 'brouillon',
                'frequence_execution' => 'hebdomadaire',
                'chef_note' => null,
                'chef_commentaire' => null,
                'direction_note' => null,
                'direction_commentaire' => null,
            ],
            [
                'type_cible' => 'qualitative',
                'statut' => 'en_cours',
                'statut_dynamique' => 'en_cours',
                'statut_validation' => 'soumise_chef',
                'progression_reelle' => 32,
                'progression_theorique' => 26,
                'kpi_delai' => 76,
                'kpi_performance' => 58,
                'kpi_conformite' => 62,
                'kpi_global' => 64,
                'weeks_filled' => 2,
                'pao_statut' => 'soumis',
                'pta_statut' => 'soumis',
                'frequence_execution' => 'hebdomadaire',
                'chef_note' => null,
                'chef_commentaire' => null,
                'direction_note' => null,
                'direction_commentaire' => null,
            ],
            [
                'type_cible' => 'quantitative',
                'statut' => 'en_cours',
                'statut_dynamique' => 'en_retard',
                'statut_validation' => 'validee_chef',
                'progression_reelle' => 41,
                'progression_theorique' => 56,
                'kpi_delai' => 61,
                'kpi_performance' => 48,
                'kpi_conformite' => 56,
                'kpi_global' => 54,
                'weeks_filled' => 2,
                'pao_statut' => 'valide',
                'pta_statut' => 'soumis',
                'frequence_execution' => 'hebdomadaire',
                'chef_note' => 62,
                'chef_commentaire' => 'Travail partiellement conforme, accelerer le rythme de production.',
                'direction_note' => null,
                'direction_commentaire' => null,
            ],
            [
                'type_cible' => 'quantitative',
                'statut' => 'en_cours',
                'statut_dynamique' => 'en_avance',
                'statut_validation' => 'validee_direction',
                'progression_reelle' => 82,
                'progression_theorique' => 67,
                'kpi_delai' => 92,
                'kpi_performance' => 84,
                'kpi_conformite' => 86,
                'kpi_global' => 88,
                'weeks_filled' => 4,
                'pao_statut' => 'valide',
                'pta_statut' => 'valide',
                'frequence_execution' => 'hebdomadaire',
                'chef_note' => 88,
                'chef_commentaire' => 'Livrables solides et respect du plan de charge.',
                'direction_note' => 91,
                'direction_commentaire' => 'Action retenue dans les statistiques consolidees.',
            ],
            [
                'type_cible' => 'quantitative',
                'statut' => 'termine',
                'statut_dynamique' => 'acheve_dans_delai',
                'statut_validation' => 'validee_direction',
                'progression_reelle' => 100,
                'progression_theorique' => 100,
                'kpi_delai' => 96,
                'kpi_performance' => 90,
                'kpi_conformite' => 95,
                'kpi_global' => 93,
                'weeks_filled' => 4,
                'pao_statut' => 'valide',
                'pta_statut' => 'valide',
                'frequence_execution' => 'hebdomadaire',
                'chef_note' => 92,
                'chef_commentaire' => 'Cloture propre, travail maitrise.',
                'direction_note' => 95,
                'direction_commentaire' => 'Cloture validee dans le delai.',
            ],
            [
                'type_cible' => 'qualitative',
                'statut' => 'en_cours',
                'statut_dynamique' => 'en_cours',
                'statut_validation' => 'rejetee_chef',
                'progression_reelle' => 57,
                'progression_theorique' => 54,
                'kpi_delai' => 72,
                'kpi_performance' => 55,
                'kpi_conformite' => 59,
                'kpi_global' => 60,
                'weeks_filled' => 3,
                'pao_statut' => 'soumis',
                'pta_statut' => 'soumis',
                'frequence_execution' => 'hebdomadaire',
                'chef_note' => 54,
                'chef_commentaire' => 'Pieces justificatives insuffisantes, reprise demandee.',
                'direction_note' => null,
                'direction_commentaire' => null,
            ],
        ];
    }

    /**
     * @return array{start:string,planned_end:string,actual_end:string}
     */
    private function buildActionDates(int $seed): array
    {
        $start = Carbon::create(2026, 1, 6)->addDays(($seed % 6) * 7);
        $plannedEnd = $start->copy()->addWeeks(8);
        $actualEnd = $plannedEnd->copy()->subDays(($seed % 3) + 1);

        return [
            'start' => $start->toDateString(),
            'planned_end' => $plannedEnd->toDateString(),
            'actual_end' => $actualEnd->toDateString(),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function createActionWeeks(int $actionId, ?int $responsableId, string $startDate, array $pattern): array
    {
        $now = now();
        $weeks = [];
        $start = Carbon::parse($startDate);
        $filledWeeks = (int) $pattern['weeks_filled'];
        $target = $pattern['type_cible'] === 'quantitative' ? 100 : null;
        $quantiteCumulee = 0.0;

        for ($week = 1; $week <= 4; $week++) {
            $weekStart = $start->copy()->addWeeks($week - 1);
            $weekEnd = $weekStart->copy()->addDays(6);
            $isFilled = $week <= $filledWeeks;

            $quantiteRealisee = null;
            $quantiteCumuleeValue = 0;
            $avancementEstime = null;
            $tachesRealisees = null;

            if ($isFilled && $pattern['type_cible'] === 'quantitative') {
                $quantiteRealisee = round(((float) $pattern['progression_reelle'] / 100) * (float) $target / max(1, $filledWeeks), 2);
                $quantiteCumulee += $quantiteRealisee;
                $quantiteCumuleeValue = round($quantiteCumulee, 2);
            }

            if ($isFilled && $pattern['type_cible'] === 'qualitative') {
                $avancementEstime = round(((float) $pattern['progression_reelle'] / max(1, $filledWeeks)) * $week, 2);
                $tachesRealisees = sprintf('Execution de la semaine %d sur l action de demonstration.', $week);
            }

            $progressionReelle = $isFilled
                ? round(((float) $pattern['progression_reelle'] / max(1, $filledWeeks)) * $week, 2)
                : 0;
            $progressionTheorique = round(min(100, $week * 25), 2);
            $ecart = round($progressionReelle - $progressionTheorique, 2);

            $weeks[] = (int) DB::table('action_weeks')->insertGetId([
                'action_id' => $actionId,
                'numero_semaine' => $week,
                'date_debut' => $weekStart->toDateString(),
                'date_fin' => $weekEnd->toDateString(),
                'est_renseignee' => $isFilled,
                'quantite_realisee' => $quantiteRealisee,
                'quantite_cumulee' => $quantiteCumuleeValue,
                'taches_realisees' => $tachesRealisees,
                'avancement_estime' => $avancementEstime,
                'commentaire' => $isFilled ? 'Saisie de demonstration' : null,
                'difficultes' => $isFilled && $week === $filledWeeks && $pattern['statut_dynamique'] === 'en_retard'
                    ? 'Retard de consolidation sur la periode.'
                    : null,
                'mesures_correctives' => $isFilled && $week === $filledWeeks
                    ? 'Arbitrage et revue hebdomadaire renforces.'
                    : null,
                'progression_reelle' => $progressionReelle,
                'progression_theorique' => $progressionTheorique,
                'ecart_progression' => $ecart,
                'saisi_par' => $isFilled ? $responsableId : null,
                'saisi_le' => $isFilled ? $weekEnd->copy()->setTime(17, 0, 0) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $weeks;
    }

    private function createActionKpis(int $actionId, array $pattern, $now): void
    {
        DB::table('action_kpis')->insert([
            'action_id' => $actionId,
            'kpi_delai' => $pattern['kpi_delai'],
            'kpi_performance' => $pattern['kpi_performance'],
            'kpi_conformite' => $pattern['kpi_conformite'],
            'kpi_global' => $pattern['kpi_global'],
            'progression_reelle' => $pattern['progression_reelle'],
            'progression_theorique' => $pattern['progression_theorique'],
            'statut_calcule' => $pattern['statut_dynamique'],
            'derniere_evaluation_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createKpiMeasure(int $actionId, ?int $responsableId, array $pattern, int $year, $now): void
    {
        $kpiId = (int) DB::table('kpis')->insertGetId([
            'action_id' => $actionId,
            'libelle' => 'KPI global action',
            'unite' => 'points',
            'cible' => 80,
            'seuil_alerte' => 60,
            'periodicite' => 'mensuel',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('kpi_mesures')->insert([
            'kpi_id' => $kpiId,
            'periode' => $year.'-03',
            'valeur' => $pattern['kpi_global'],
            'commentaire' => 'Mesure de demonstration pour le tableau de bord.',
            'saisi_par' => $responsableId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createActionLogs(
        int $actionId,
        array $weeks,
        ?int $responsableId,
        ?int $chefId,
        ?int $directeurId,
        array $pattern,
        $now
    ): void {
        $logs = [];

        if ($pattern['weeks_filled'] > 0) {
            $logs[] = [
                'action_id' => $actionId,
                'action_week_id' => $weeks[min(count($weeks), (int) $pattern['weeks_filled']) - 1] ?? null,
                'niveau' => 'info',
                'type_evenement' => 'suivi_hebdomadaire',
                'message' => 'Une saisie hebdomadaire de demonstration a ete enregistree.',
                'details' => json_encode(['progression' => $pattern['progression_reelle']], JSON_UNESCAPED_UNICODE),
                'cible_role' => User::ROLE_SERVICE,
                'utilisateur_id' => $responsableId,
                'lu' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($pattern['statut_validation'] !== 'non_soumise') {
            $logs[] = [
                'action_id' => $actionId,
                'action_week_id' => null,
                'niveau' => 'info',
                'type_evenement' => 'soumission_action',
                'message' => 'L action a ete soumise dans le circuit de validation.',
                'details' => json_encode(['statut_validation' => $pattern['statut_validation']], JSON_UNESCAPED_UNICODE),
                'cible_role' => User::ROLE_SERVICE,
                'utilisateur_id' => $responsableId,
                'lu' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (in_array($pattern['statut_validation'], ['rejetee_chef', 'validee_chef', 'validee_direction'], true)) {
            $logs[] = [
                'action_id' => $actionId,
                'action_week_id' => null,
                'niveau' => $pattern['statut_validation'] === 'rejetee_chef' ? 'warning' : 'info',
                'type_evenement' => 'evaluation_service',
                'message' => (string) ($pattern['chef_commentaire'] ?? 'Evaluation du chef de service enregistree.'),
                'details' => json_encode(['note' => $pattern['chef_note']], JSON_UNESCAPED_UNICODE),
                'cible_role' => User::ROLE_AGENT,
                'utilisateur_id' => $chefId,
                'lu' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($pattern['statut_validation'] === 'validee_direction') {
            $logs[] = [
                'action_id' => $actionId,
                'action_week_id' => null,
                'niveau' => 'info',
                'type_evenement' => 'validation_direction',
                'message' => (string) ($pattern['direction_commentaire'] ?? 'Validation direction enregistree.'),
                'details' => json_encode(['note' => $pattern['direction_note']], JSON_UNESCAPED_UNICODE),
                'cible_role' => User::ROLE_DIRECTION,
                'utilisateur_id' => $directeurId,
                'lu' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($logs !== []) {
            DB::table('action_logs')->insert($logs);
        }
    }
}
