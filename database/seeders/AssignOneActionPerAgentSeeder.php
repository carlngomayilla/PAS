<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssignOneActionPerAgentSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $targetYear = 2026;

        $pasId = (int) (DB::table('pas')->orderByDesc('id')->value('id') ?? 0);
        if ($pasId <= 0) {
            $pasId = (int) DB::table('pas')->insertGetId([
                'titre' => 'PAS OPERATIONNEL TEST '.$targetYear.'-'.($targetYear + 2),
                'periode_debut' => $targetYear,
                'periode_fin' => $targetYear + 2,
                'statut' => 'brouillon',
                'created_by' => null,
                'valide_le' => null,
                'valide_par' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $agents = DB::table('users')
            ->where('role', User::ROLE_AGENT)
            ->whereNotNull('direction_id')
            ->orderBy('id')
            ->get(['id', 'direction_id', 'service_id', 'agent_matricule', 'name']);

        if ($agents->isEmpty()) {
            return;
        }

        $pasObjectifSeedId = (int) (DB::table('pas_objectifs')
            ->join('pas_axes', 'pas_axes.id', '=', 'pas_objectifs.pas_axe_id')
            ->where('pas_axes.pas_id', $pasId)
            ->orderBy('pas_axes.ordre')
            ->orderBy('pas_axes.id')
            ->orderBy('pas_objectifs.ordre')
            ->orderBy('pas_objectifs.id')
            ->value('pas_objectifs.id') ?? 0);

        if ($pasObjectifSeedId <= 0) {
            $axeId = (int) DB::table('pas_axes')->insertGetId([
                'pas_id' => $pasId,
                'direction_id' => null,
                'code' => 'AXE-1',
                'libelle' => 'Axe strategique initial',
                'periode_debut' => $targetYear.'-01-01',
                'periode_fin' => ($targetYear + 2).'-12-31',
                'description' => 'Axe genere automatiquement pour l attribution initiale des actions.',
                'ordre' => 1,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $pasObjectifSeedId = (int) DB::table('pas_objectifs')->insertGetId([
                'pas_axe_id' => $axeId,
                'code' => 'OS-1.1',
                'libelle' => 'Objectif strategique initial',
                'description' => 'Objectif genere automatiquement pour permettre la creation des PAO.',
                'ordre' => 1,
                'indicateur_global' => 'Couverture initiale des PAO',
                'valeur_cible' => '100%',
                'valeurs_cible' => json_encode([
                    'indicateur_global' => 'Couverture initiale des PAO',
                    'valeur_cible' => '100%',
                ], JSON_UNESCAPED_UNICODE),
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $directionIds = $agents
            ->pluck('direction_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        foreach ($directionIds as $directionId) {
            DB::table('pas_directions')->updateOrInsert(
                ['pas_id' => $pasId, 'direction_id' => $directionId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        $paoByDirectionService = [];
        $ptaByDirectionService = [];
        foreach ($agents as $agent) {
            $directionId = (int) $agent->direction_id;
            $serviceId = $agent->service_id !== null ? (int) $agent->service_id : null;

            if ($serviceId === null) {
                $serviceId = (int) (DB::table('services')
                    ->where('direction_id', $directionId)
                    ->orderBy('id')
                    ->value('id') ?? 0);
            }

            if ($serviceId <= 0) {
                continue;
            }

            $key = $directionId.'-'.$serviceId;
            if (isset($ptaByDirectionService[$key])) {
                continue;
            }

            $paoId = (int) (DB::table('paos')
                ->where('pas_objectif_id', $pasObjectifSeedId)
                ->where('annee', $targetYear)
                ->where('direction_id', $directionId)
                ->where('service_id', $serviceId)
                ->value('id') ?? 0);

            if ($paoId <= 0) {
                $directionCode = (string) (DB::table('directions')->where('id', $directionId)->value('code') ?? 'DIR');
                $serviceCode = (string) (DB::table('services')->where('id', $serviceId)->value('code') ?? 'SRV');
                $paoId = (int) DB::table('paos')->insertGetId([
                    'pas_id' => $pasId,
                    'pas_objectif_id' => $pasObjectifSeedId,
                    'direction_id' => $directionId,
                    'service_id' => $serviceId,
                    'annee' => $targetYear,
                    'titre' => 'PAO '.$directionCode.' '.$serviceCode.' '.$targetYear,
                    'echeance' => $targetYear.'-12-31',
                    'objectif_operationnel' => 'Assurer le pilotage des actions operationnelles',
                    'resultats_attendus' => 'Attribution et suivi des actions agents',
                    'indicateurs_associes' => 'Taux d actions renseignees',
                    'statut' => 'brouillon',
                    'valide_le' => null,
                    'valide_par' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($paoId <= 0) {
                continue;
            }

            $paoByDirectionService[$key] = $paoId;

            $ptaId = (int) (DB::table('ptas')
                ->where('pao_id', $paoId)
                ->value('id') ?? 0);

            if ($ptaId <= 0) {
                $serviceCode = (string) (DB::table('services')->where('id', $serviceId)->value('code') ?? 'SRV');
                $ptaId = (int) DB::table('ptas')->insertGetId([
                    'pao_id' => $paoId,
                    'direction_id' => $directionId,
                    'service_id' => $serviceId,
                    'titre' => 'PTA '.$serviceCode.' '.$targetYear,
                    'description' => 'PTA genere pour attribution des actions agents',
                    'statut' => 'brouillon',
                    'valide_le' => null,
                    'valide_par' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $ptaByDirectionService[$key] = $ptaId;
        }

        foreach ($agents as $agent) {
            $directionId = (int) $agent->direction_id;
            $serviceId = $agent->service_id !== null
                ? (int) $agent->service_id
                : (int) (DB::table('services')->where('direction_id', $directionId)->orderBy('id')->value('id') ?? 0);

            if ($serviceId <= 0) {
                continue;
            }

            $ptaKey = $directionId.'-'.$serviceId;
            $ptaId = $ptaByDirectionService[$ptaKey] ?? null;
            if ($ptaId === null) {
                continue;
            }

            $existingActionId = DB::table('actions')
                ->where('responsable_id', (int) $agent->id)
                ->value('id');

            if ($existingActionId !== null) {
                continue;
            }

            $matricule = trim((string) ($agent->agent_matricule ?? 'AGENT-'.$agent->id));
            $libelle = 'Action individuelle '.$matricule;

            $actionId = (int) DB::table('actions')->insertGetId([
                'pta_id' => $ptaId,
                'libelle' => $libelle,
                'description' => 'Action assignee automatiquement a '.$agent->name,
                'type_cible' => 'quantitative',
                'unite_cible' => 'taches',
                'quantite_cible' => 100,
                'resultat_attendu' => null,
                'criteres_validation' => null,
                'livrable_attendu' => null,
                'date_debut' => $targetYear.'-01-01',
                'date_fin' => $targetYear.'-12-31',
                'date_echeance' => $targetYear.'-12-31',
                'responsable_id' => (int) $agent->id,
                'statut' => 'non_demarre',
                'statut_dynamique' => 'non_demarre',
                'progression_reelle' => 0,
                'progression_theorique' => 0,
                'seuil_alerte_progression' => 10,
                'risques' => null,
                'mesures_preventives' => null,
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
                'date_debut' => $targetYear.'-01-01',
                'date_fin' => $targetYear.'-01-07',
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
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('action_kpis')->insert([
                'action_id' => $actionId,
                'kpi_delai' => 0,
                'kpi_performance' => 0,
                'kpi_conformite' => 100,
                'kpi_global' => 20,
                'progression_reelle' => 0,
                'progression_theorique' => 0,
                'statut_calcule' => 'non_demarre',
                'derniere_evaluation_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
