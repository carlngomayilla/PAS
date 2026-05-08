<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PtaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'titre' => $this->titre,
            'pao_id' => $this->pao_id,
            'objectif_operationnel_id' => $this->objectif_operationnel_id,
            'direction_id' => $this->direction_id,
            'service_id' => $this->service_id,
            'description' => $this->description,
            'statut' => $this->statut,
            'valide_le' => optional($this->valide_le)->toIso8601String(),
            'pao' => PaoResource::make($this->whenLoaded('pao')),
            'objectif_operationnel' => $this->whenLoaded('objectifOperationnel'),
            'direction' => $this->whenLoaded('direction'),
            'service' => $this->whenLoaded('service'),
            'validateur' => UserLiteResource::make($this->whenLoaded('validateur')),
            'actions_count' => $this->whenCounted('actions'),
            'actions' => ActionResource::collection($this->whenLoaded('actions')),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
