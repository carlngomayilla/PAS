<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'libelle' => $this->libelle,
            'pta_id' => $this->pta_id,
            'pao_id' => $this->pao_id,
            'responsable_id' => $this->responsable_id,
            'description' => $this->description,
            'contexte_action' => $this->contexte_action,
            'origine_action' => $this->origine_action,
            'objectif_operationnel_id' => $this->objectif_operationnel_id,
            'mode_evaluation' => $this->resolvedEvaluationMode(),
            'mode_evaluation_label' => $this->mode_evaluation_label,
            'cible_mesurable_attendue' => $this->quantite_cible !== null ? (float) $this->quantite_cible : null,
            'unite_mesure' => $this->unite_cible,
            'quantite_realisee' => $this->quantite_realisee !== null ? (float) $this->quantite_realisee : null,
            'avancement_operationnel' => $this->avancement_operationnel !== null ? (float) $this->avancement_operationnel : null,
            'taux_atteinte_cible' => $this->taux_atteinte_cible !== null ? (float) $this->taux_atteinte_cible : null,
            'taux_global' => $this->taux_global !== null ? (float) $this->taux_global : null,
            'statut' => $this->statut,
            'statut_dynamique' => $this->statut_dynamique,
            'statut_label' => $this->status_label,
            'statut_validation' => $this->statut_validation,
            'statut_validation_label' => $this->validation_status_label,
            'progression_reelle' => $this->progression_reelle !== null ? (float) $this->progression_reelle : null,
            'progression_theorique' => $this->progression_theorique !== null ? (float) $this->progression_theorique : null,
            'date_debut' => optional($this->date_debut)->toDateString(),
            'date_fin' => optional($this->date_fin)->toDateString(),
            'date_echeance' => optional($this->date_echeance)->toDateString(),
            'ressources' => [
                'ressources_necessaires' => $this->ressources_necessaires ?? [],
                'ressources_labels' => $this->resourceLabels(),
                'details' => $this->ressources_details,
            ],
            'financement' => [
                'financement_requis' => (bool) $this->financement_requis,
                'budget_prevu' => $this->montant_estime !== null ? (float) $this->montant_estime : null,
                'nature_financement' => $this->nature_financement ?: $this->description_financement,
                'source_financement' => $this->source_financement,
                'commentaire_financement' => $this->commentaire_financement,
                'statut_financement' => $this->financementStatus(),
                'statut_financement_label' => $this->financement_status_label,
                'commentaire_daf' => $this->financement_daf_commentaire,
                'montant_valide_daf' => $this->financement_montant_valide !== null ? (float) $this->financement_montant_valide : null,
            ],
            'responsable' => UserLiteResource::make($this->whenLoaded('responsable')),
            'rmos' => $this->whenLoaded('responsables', fn () => UserLiteResource::collection($this->responsables)),
            'pta' => PtaResource::make($this->whenLoaded('pta')),
            'pao' => PaoResource::make($this->whenLoaded('pao')),
            'action_kpi' => $this->whenLoaded('actionKpi', fn () => [
                'kpi_delai' => (float) ($this->actionKpi?->kpi_delai ?? 0),
                'kpi_performance' => (float) ($this->actionKpi?->kpi_performance ?? 0),
                'kpi_conformite' => (float) (0),
                'kpi_global' => (float) ($this->actionKpi?->kpi_global ?? 0),
                'progression_reelle' => (float) ($this->actionKpi?->progression_reelle ?? 0),
                'progression_theorique' => (float) ($this->actionKpi?->progression_theorique ?? 0),
                'statut_calcule' => $this->actionKpi?->statut_calcule,
            ]),
            'primary_kpi' => $this->whenLoaded('primaryKpi'),
            'kpis_count' => $this->whenCounted('kpis'),
            'semaines_total' => $this->when(isset($this->semaines_total), $this->semaines_total),
            'semaines_renseignees' => $this->when(isset($this->semaines_renseignees), $this->semaines_renseignees),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
