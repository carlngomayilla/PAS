<?php

namespace Database\Seeders;

use App\Models\Action;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $this->call([
            DirectionServiceStructureSeeder::class,
            SyncOrgUsersSeeder::class,
        ]);

        DB::table('users')->updateOrInsert(
            ['email' => 'admin@anbg.test'],
            [
                'name' => 'Administrateur ANBG',
                'password' => Hash::make('Pass@12345'),
                'role' => User::ROLE_ADMIN,
                'is_agent' => false,
                'direction_id' => null,
                'service_id' => null,
                'email_verified_at' => $now,
                'password_changed_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $adminId = (int) DB::table('users')->where('email', 'admin@anbg.test')->value('id');

        DB::transaction(function () use ($adminId, $now): void {
            DB::table('ptas')->delete();
            DB::table('paos')->delete();
            DB::table('pas')->delete();
            DB::table('justificatifs')
                ->where('justifiable_type', Action::class)
                ->delete();

            $directionIds = DB::table('directions')
                ->whereIn('code', ['DS', 'DSIC', 'DAF'])
                ->pluck('id', 'code')
                ->all();

            $serviceIds = DB::table('services')
                ->whereIn('code', ['DS-SP', 'DSIC-SGDS', 'DAF-SFC'])
                ->pluck('id', 'code')
                ->all();

            $pasId = (int) DB::table('pas')->insertGetId([
                'titre' => 'PAS TEST ANBG 2026-2028',
                'periode_debut' => 2026,
                'periode_fin' => 2028,
                'statut' => 'brouillon',
                'valide_le' => null,
                'valide_par' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $axes = [
                [
                    'code' => 'AXE-DS',
                    'libelle' => 'Amelioration du suivi scolarite',
                    'direction_code' => 'DS',
                    'ordre' => 1,
                    'objectifs' => [
                        [
                            'code' => 'OS-DS-01',
                            'libelle' => 'Fluidifier le traitement des dossiers',
                            'indicateur_global' => 'Taux de dossiers traites dans les delais',
                            'valeur_cible' => '90%',
                        ],
                    ],
                ],
                [
                    'code' => 'AXE-DSIC',
                    'libelle' => 'Renforcement des systemes d information',
                    'direction_code' => 'DSIC',
                    'ordre' => 2,
                    'objectifs' => [
                        [
                            'code' => 'OS-DSIC-01',
                            'libelle' => 'Securiser les plateformes critiques',
                            'indicateur_global' => 'Disponibilite des services',
                            'valeur_cible' => '99%',
                        ],
                    ],
                ],
                [
                    'code' => 'AXE-DAF',
                    'libelle' => 'Performance administrative et financiere',
                    'direction_code' => 'DAF',
                    'ordre' => 3,
                    'objectifs' => [
                        [
                            'code' => 'OS-DAF-01',
                            'libelle' => 'Maitriser les delais de traitement financier',
                            'indicateur_global' => 'Taux de traitements conformes',
                            'valeur_cible' => '95%',
                        ],
                    ],
                ],
            ];

            $pasDirectionIds = [];
            $objectifIdsByDirection = [];
            foreach ($axes as $axe) {
                $directionId = isset($directionIds[$axe['direction_code']])
                    ? (int) $directionIds[$axe['direction_code']]
                    : null;

                $axeId = (int) DB::table('pas_axes')->insertGetId([
                    'pas_id' => $pasId,
                    'direction_id' => $directionId,
                    'code' => $axe['code'],
                    'libelle' => $axe['libelle'],
                    'description' => null,
                    'ordre' => $axe['ordre'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($directionId !== null) {
                    $pasDirectionIds[$directionId] = true;
                }

                foreach ($axe['objectifs'] as $objectif) {
                    $objectifId = (int) DB::table('pas_objectifs')->insertGetId([
                        'pas_axe_id' => $axeId,
                        'code' => $objectif['code'],
                        'libelle' => $objectif['libelle'],
                        'description' => null,
                        'ordre' => 1,
                        'indicateur_global' => $objectif['indicateur_global'],
                        'valeur_cible' => $objectif['valeur_cible'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if ($directionId !== null) {
                        $objectifIdsByDirection[$directionId] = $objectifId;
                    }
                }
            }

            foreach (array_keys($pasDirectionIds) as $directionId) {
                DB::table('pas_directions')->insert([
                    'pas_id' => $pasId,
                    'direction_id' => (int) $directionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $paos = [
                [
                    'direction_code' => 'DS',
                    'annee' => 2026,
                    'titre' => 'PAO TEST DS 2026',
                    'objectif_operationnel' => 'Structurer le suivi des services de scolarite',
                    'resultats_attendus' => 'Reduction des retards de traitement',
                    'indicateurs_associes' => 'Delai moyen de traitement',
                    'echeance' => '2026-12-31',
                ],
                [
                    'direction_code' => 'DSIC',
                    'annee' => 2026,
                    'titre' => 'PAO TEST DSIC 2026',
                    'objectif_operationnel' => 'Stabiliser les services numeriques',
                    'resultats_attendus' => 'Amelioration de la disponibilite',
                    'indicateurs_associes' => 'Taux de disponibilite',
                    'echeance' => '2026-12-31',
                ],
                [
                    'direction_code' => 'DAF',
                    'annee' => 2026,
                    'titre' => 'PAO TEST DAF 2026',
                    'objectif_operationnel' => 'Optimiser les circuits financiers',
                    'resultats_attendus' => 'Conformite et rapidite des traitements',
                    'indicateurs_associes' => 'Taux de dossiers conformes',
                    'echeance' => '2026-12-31',
                ],
            ];

            $paoIds = [];
            foreach ($paos as $pao) {
                if (! isset($directionIds[$pao['direction_code']])) {
                    continue;
                }

                $directionId = (int) $directionIds[$pao['direction_code']];
                $objectifId = $objectifIdsByDirection[$directionId] ?? null;
                if (! is_int($objectifId) || $objectifId <= 0) {
                    continue;
                }

                $paoIds[$pao['direction_code']] = (int) DB::table('paos')->insertGetId([
                    'pas_id' => $pasId,
                    'pas_objectif_id' => $objectifId,
                    'direction_id' => $directionId,
                    'annee' => $pao['annee'],
                    'titre' => $pao['titre'],
                    'echeance' => $pao['echeance'],
                    'objectif_operationnel' => $pao['objectif_operationnel'],
                    'resultats_attendus' => $pao['resultats_attendus'],
                    'indicateurs_associes' => $pao['indicateurs_associes'],
                    'statut' => 'brouillon',
                    'valide_le' => null,
                    'valide_par' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $ptas = [
                ['direction_code' => 'DS', 'service_code' => 'DS-SP', 'titre' => 'PTA TEST DS-SP 2026'],
                ['direction_code' => 'DSIC', 'service_code' => 'DSIC-SGDS', 'titre' => 'PTA TEST DSIC-SGDS 2026'],
                ['direction_code' => 'DAF', 'service_code' => 'DAF-SFC', 'titre' => 'PTA TEST DAF-SFC 2026'],
            ];

            $ptaIds = [];
            foreach ($ptas as $pta) {
                if (! isset($directionIds[$pta['direction_code']], $serviceIds[$pta['service_code']], $paoIds[$pta['direction_code']])) {
                    continue;
                }

                $directionId = (int) $directionIds[$pta['direction_code']];
                $serviceId = (int) $serviceIds[$pta['service_code']];
                $paoId = (int) $paoIds[$pta['direction_code']];

                $ptaIds[$pta['direction_code']] = (int) DB::table('ptas')->insertGetId([
                    'pao_id' => $paoId,
                    'direction_id' => $directionId,
                    'service_id' => $serviceId,
                    'titre' => $pta['titre'],
                    'description' => 'PTA de test genere automatiquement.',
                    'statut' => 'brouillon',
                    'valide_le' => null,
                    'valide_par' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $responsables = DB::table('users')
                ->whereIn('agent_matricule', ['C1-18', 'B1-09', 'B2-06'])
                ->pluck('id', 'agent_matricule')
                ->all();

            $actions = [
                [
                    'direction_code' => 'DS',
                    'libelle' => 'Ameliorer la planification des activites DS',
                    'responsable_matricule' => 'C1-18',
                    'type_cible' => 'qualitative',
                    'resultat_attendu' => 'Planification harmonisee des activites de la direction',
                    'statut' => 'en_cours',
                    'statut_dynamique' => 'en_cours',
                    'progression_reelle' => 35,
                ],
                [
                    'direction_code' => 'DSIC',
                    'libelle' => 'Consolider les statistiques documentaires DSIC',
                    'responsable_matricule' => 'B1-09',
                    'type_cible' => 'quantitative',
                    'unite_cible' => 'rapports',
                    'quantite_cible' => 12,
                    'statut' => 'en_cours',
                    'statut_dynamique' => 'en_cours',
                    'progression_reelle' => 40,
                ],
                [
                    'direction_code' => 'DAF',
                    'libelle' => 'Suivre les paiements de bourses mensuels',
                    'responsable_matricule' => 'B2-06',
                    'type_cible' => 'quantitative',
                    'unite_cible' => 'dossiers',
                    'quantite_cible' => 300,
                    'statut' => 'en_cours',
                    'statut_dynamique' => 'en_retard',
                    'progression_reelle' => 25,
                ],
            ];

            foreach ($actions as $action) {
                if (! isset($ptaIds[$action['direction_code']])) {
                    continue;
                }

                $responsableId = $responsables[$action['responsable_matricule']] ?? null;
                $actionId = (int) DB::table('actions')->insertGetId([
                    'pta_id' => (int) $ptaIds[$action['direction_code']],
                    'libelle' => $action['libelle'],
                    'description' => 'Action de test',
                    'type_cible' => $action['type_cible'],
                    'unite_cible' => $action['unite_cible'] ?? null,
                    'quantite_cible' => $action['quantite_cible'] ?? null,
                    'resultat_attendu' => $action['resultat_attendu'] ?? null,
                    'criteres_validation' => null,
                    'livrable_attendu' => null,
                    'date_debut' => '2026-01-01',
                    'date_fin' => '2026-12-31',
                    'date_echeance' => '2026-12-31',
                    'responsable_id' => $responsableId !== null ? (int) $responsableId : null,
                    'statut' => $action['statut'],
                    'statut_dynamique' => $action['statut_dynamique'],
                    'progression_reelle' => $action['progression_reelle'],
                    'progression_theorique' => 30,
                    'seuil_alerte_progression' => 10,
                    'risques' => 'Risque de retard de collecte',
                    'mesures_preventives' => 'Relances hebdomadaires',
                    'financement_requis' => false,
                    'ressource_main_oeuvre' => true,
                    'ressource_equipement' => false,
                    'ressource_partenariat' => false,
                    'ressource_autres' => false,
                    'description_financement' => null,
                    'source_financement' => null,
                    'montant_estime' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('action_weeks')->insert([
                    'action_id' => $actionId,
                    'numero_semaine' => 1,
                    'date_debut' => '2026-01-01',
                    'date_fin' => '2026-01-07',
                    'est_renseignee' => true,
                    'quantite_realisee' => $action['type_cible'] === 'quantitative' ? 10 : null,
                    'quantite_cumulee' => $action['type_cible'] === 'quantitative' ? 10 : 0,
                    'taches_realisees' => 'Execution initiale',
                    'avancement_estime' => $action['type_cible'] === 'qualitative' ? 30 : null,
                    'commentaire' => 'Semaine de lancement',
                    'difficultes' => 'Aucune critique',
                    'mesures_correctives' => 'RAS',
                    'progression_reelle' => $action['progression_reelle'],
                    'progression_theorique' => 10,
                    'ecart_progression' => $action['progression_reelle'] - 10,
                    'saisi_par' => $responsableId !== null ? (int) $responsableId : null,
                    'saisi_le' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('action_kpis')->insert([
                    'action_id' => $actionId,
                    'kpi_delai' => 85,
                    'kpi_performance' => $action['progression_reelle'],
                    'kpi_conformite' => 90,
                    'kpi_global' => round((0.4 * 85) + (0.4 * (float) $action['progression_reelle']) + (0.2 * 90), 2),
                    'progression_reelle' => $action['progression_reelle'],
                    'progression_theorique' => 30,
                    'statut_calcule' => $action['statut_dynamique'],
                    'derniere_evaluation_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
