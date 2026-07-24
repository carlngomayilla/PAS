<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresPlanningWriter;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\PasObjectif;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdatePaoRequest extends FormRequest
{
    use RequiresPlanningWriter;

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $pao = $this->route('pao');

        if ($pao instanceof Pao && ! Gate::forUser($user)->allows('update', $pao)) {
            return false;
        }

        $directionId = $this->input('direction_id');

        return Gate::forUser($user)->allows('create', [
            Pao::class,
            is_numeric($directionId) ? (int) $directionId : null,
        ]);
    }

    protected function prepareForValidation(): void
    {
        /** @var Pao|null $pao */
        $pao = $this->route('pao');
        $objectifs = $this->input('objectifs_operationnels');

        if (! is_array($objectifs) || $objectifs === []) {
            $objectifs = [[
                'libelle' => $this->input('objectif_operationnel') ?: $this->input('titre') ?: $pao?->objectif_operationnel,
                'service_id' => $this->input('service_id') ?: $pao?->service_id,
                'echeance' => $this->input('echeance') ?: optional($pao?->echeance)->format('Y-m-d'),
            ]];
        }

        $this->merge([
            'objectifs_operationnels' => collect($objectifs)
                ->filter(fn ($objectif): bool => is_array($objectif))
                ->map(function (array $objectif): array {
                    $objectif['pas_objectif_id'] = $objectif['pas_objectif_id'] ?? $this->input('pas_objectif_id');

                    return $objectif;
                })
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'pas_axe_id' => ['required', 'integer', 'exists:pas_axes,id'],
            'pas_objectif_id' => ['required', 'integer', 'exists:pas_objectifs,id'],
            'direction_id' => ['required', 'integer', 'exists:directions,id'],
            'annee' => ['required', 'integer', 'digits:4', 'min:2000'],
            'titre' => ['nullable', 'string', 'max:255'],
            'objectifs_operationnels' => ['required', 'array', 'min:1'],
            'objectifs_operationnels.*.id' => ['nullable', 'integer', 'exists:objectifs_operationnels,id'],
            'objectifs_operationnels.*.pas_objectif_id' => ['required', 'integer', 'exists:pas_objectifs,id'],
            'objectifs_operationnels.*.libelle' => ['required', 'string'],
            'objectifs_operationnels.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'objectifs_operationnels.*.echeance' => ['required', 'date', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pas_axe_id.required' => 'L axe strategique est obligatoire avant de choisir l objectif strategique.',
            'objectifs_operationnels.required' => 'Ajoutez au moins un objectif operationnel.',
            'objectifs_operationnels.*.pas_objectif_id.required' => 'Le rattachement strategique de chaque objectif operationnel est obligatoire.',
            'objectifs_operationnels.*.libelle.required' => 'Le libelle de chaque objectif operationnel est obligatoire.',
            'objectifs_operationnels.*.service_id.required' => 'Le service destinataire de chaque objectif operationnel est obligatoire.',
            'objectifs_operationnels.*.echeance.required' => 'L echeance de chaque objectif operationnel est obligatoire.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $objectif = PasObjectif::query()
                ->with('pasAxe.pas:id,periode_debut,periode_fin')
                ->find((int) $this->input('pas_objectif_id'));
            $strategicObjectiveIds = collect((array) $this->input('objectifs_operationnels', []))
                ->pluck('pas_objectif_id')
                ->filter(fn ($id): bool => is_numeric($id))
                ->map(fn ($id): int => (int) $id)
                ->push((int) $this->input('pas_objectif_id'))
                ->unique()
                ->values();
            $strategicObjectives = PasObjectif::query()
                ->with('pasAxe.pas:id,periode_debut,periode_fin')
                ->whereIn('id', $strategicObjectiveIds->all())
                ->get()
                ->keyBy('id');
            $serviceIds = collect((array) $this->input('objectifs_operationnels', []))
                ->pluck('service_id')
                ->filter(fn ($id): bool => is_numeric($id))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();
            $services = Service::query()->whereIn('id', $serviceIds->all())->get()->keyBy('id');

            $pas = $objectif?->pasAxe?->pas;
            if ($objectif !== null && (int) $objectif->pas_axe_id !== (int) $this->input('pas_axe_id')) {
                $validator->errors()->add(
                    'pas_objectif_id',
                    'L objectif strategique selectionne doit appartenir a l axe strategique choisi.'
                );
            }

            if ($pas !== null) {
                $annee = (int) $this->input('annee');

                if ($annee < (int) $pas->periode_debut || $annee > (int) $pas->periode_fin) {
                    $validator->errors()->add(
                        'annee',
                        'L annee du PAO doit etre comprise dans la periode du PAS parent.'
                    );
                }
            }

            foreach ((array) $this->input('objectifs_operationnels', []) as $index => $operationalObjective) {
                if (! is_array($operationalObjective)) {
                    continue;
                }

                $serviceId = is_numeric($operationalObjective['service_id'] ?? null) ? (int) $operationalObjective['service_id'] : null;
                $service = $serviceId !== null ? $services->get($serviceId) : null;
                $operationalStrategicObjectiveId = (int) ($operationalObjective['pas_objectif_id'] ?? 0);
                $operationalStrategicObjective = $strategicObjectives->get($operationalStrategicObjectiveId);
                $operationalPas = $operationalStrategicObjective?->pasAxe?->pas;

                if ($pas !== null && $operationalPas !== null && (int) $operationalPas->id !== (int) $pas->id) {
                    $validator->errors()->add(
                        "objectifs_operationnels.{$index}.pas_objectif_id",
                        'Tous les objectifs operationnels du PAO doivent appartenir au meme PAS.'
                    );
                }

                if ($service !== null && (int) $service->direction_id !== (int) $this->input('direction_id')) {
                    $validator->errors()->add(
                        "objectifs_operationnels.{$index}.service_id",
                        'Le service selectionne doit appartenir a la direction du PAO.'
                    );
                }

                $echeance = $operationalObjective['echeance'] ?? null;
                if ($echeance !== null) {
                    $annee = (int) $this->input('annee');
                    $echeanceYear = (int) date('Y', strtotime((string) $echeance));

                    if ($echeanceYear !== $annee) {
                        $validator->errors()->add(
                            "objectifs_operationnels.{$index}.echeance",
                            'L echeance doit appartenir a la meme annee que le PAO.'
                        );
                    }
                }

                if ($pas !== null && $echeance !== null) {
                    $echeanceYear = (int) date('Y', strtotime((string) $echeance));
                    if ($echeanceYear < (int) $pas->periode_debut || $echeanceYear > (int) $pas->periode_fin) {
                        $validator->errors()->add(
                            "objectifs_operationnels.{$index}.echeance",
                            'L echeance doit rester dans la periode du PAS parent.'
                        );
                    }
                }

                if ($operationalStrategicObjective?->date_echeance !== null && $echeance !== null) {
                    $objectiveDeadline = strtotime((string) $operationalStrategicObjective->date_echeance);
                    $operationalDeadline = strtotime((string) $echeance);
                    if ($objectiveDeadline !== false && $operationalDeadline !== false && $operationalDeadline > $objectiveDeadline) {
                        $validator->errors()->add(
                            "objectifs_operationnels.{$index}.echeance",
                            'L echeance de l objectif operationnel ne doit pas depasser celle de l objectif strategique.'
                        );
                    }
                }
            }

            $seenKeys = [];
            foreach ((array) $this->input('objectifs_operationnels', []) as $index => $operationalObjective) {
                if (! is_array($operationalObjective)) {
                    continue;
                }

                $key = implode('|', [
                    (int) ($operationalObjective['pas_objectif_id'] ?? 0),
                    (int) $this->input('annee'),
                    (int) $this->input('direction_id'),
                    (int) ($operationalObjective['service_id'] ?? 0),
                    mb_strtolower(trim((string) ($operationalObjective['libelle'] ?? ''))),
                ]);

                if (isset($seenKeys[$key])) {
                    $validator->errors()->add(
                        "objectifs_operationnels.{$index}.libelle",
                        'Deux objectifs operationnels identiques ne peuvent pas etre ajoutes au meme PAO.'
                    );
                }

                $seenKeys[$key] = true;
            }

            $currentPaoId = $this->resolveCurrentPaoId();
            $existingPao = Pao::query()
                ->where('direction_id', (int) $this->input('direction_id'))
                ->where('annee', (int) $this->input('annee'))
                ->when($currentPaoId !== null, fn ($query) => $query->whereKeyNot($currentPaoId))
                ->exists();

            if ($existingPao) {
                $validator->errors()->add(
                    'direction_id',
                    'Un PAO existe deja pour cette direction et cette annee. Modifiez le PAO existant pour ajouter ou ajuster les objectifs operationnels.'
                );
            }

            $pao = $this->route('pao');
            if ($pao instanceof Pao) {
                $submittedObjectiveIds = collect((array) $this->input('objectifs_operationnels', []))
                    ->pluck('id')
                    ->filter(fn ($id): bool => is_numeric($id))
                    ->map(fn ($id): int => (int) $id)
                    ->values();
                $foreignObjectiveExists = $submittedObjectiveIds->isNotEmpty()
                    && ObjectifOperationnel::query()
                        ->whereIn('id', $submittedObjectiveIds->all())
                        ->where('pao_id', '!=', $pao->id)
                        ->exists();

                if ($foreignObjectiveExists) {
                    $validator->errors()->add(
                        'objectifs_operationnels',
                        'Un objectif operationnel transmis ne correspond pas au PAO modifie.'
                    );
                }

                $protectedRemovedObjectives = $pao->objectifsOperationnels()
                    ->whereNotIn('id', $submittedObjectiveIds->all())
                    ->where(function ($query): void {
                        $query->whereHas('ptas')->orWhereHas('actions');
                    })
                    ->count();

                if ($protectedRemovedObjectives > 0) {
                    $validator->errors()->add(
                        'objectifs_operationnels',
                        'Impossible de retirer un objectif operationnel deja utilise par un PTA ou une action.'
                    );
                }
            }
        });
    }

    private function resolveCurrentPaoId(): ?int
    {
        $routeValue = $this->route('pao') ?? $this->input('id');

        if (is_object($routeValue) && method_exists($routeValue, 'getKey')) {
            return (int) $routeValue->getKey();
        }

        if (is_numeric($routeValue)) {
            return (int) $routeValue;
        }

        return null;
    }
}
