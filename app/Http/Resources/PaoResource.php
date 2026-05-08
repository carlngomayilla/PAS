<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'titre' => $this->titre,
            'pas_id' => $this->pas_id,
            'pas_objectif_id' => $this->pas_objectif_id,
            'direction_id' => $this->direction_id,
            'service_id' => $this->service_id,
            'annee' => $this->annee,
            'objectif_operationnel' => $this->objectif_operationnel,
            'resultats_attendus' => $this->resultats_attendus,
            'indicateurs_associes' => $this->indicateurs_associes,
            'statut' => $this->statut,
            'echeance' => optional($this->echeance)->toDateString(),
            'valide_le' => optional($this->valide_le)->toIso8601String(),
            'pas' => $this->whenLoaded('pas'),
            'pas_objectif' => $this->whenLoaded('pasObjectif'),
            'direction' => $this->whenLoaded('direction'),
            'service' => $this->whenLoaded('service'),
            'validateur' => UserLiteResource::make($this->whenLoaded('validateur')),
            'ptas_count' => $this->whenCounted('ptas'),
            'objectifs_operationnels_count' => $this->whenCounted('objectifsOperationnels'),
            'objectifs_operationnels' => $this->whenLoaded('objectifsOperationnels'),
            'axes' => $this->whenLoaded('axes'),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
