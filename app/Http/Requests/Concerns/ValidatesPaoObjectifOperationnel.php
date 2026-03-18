<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesPaoObjectifOperationnel
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function paoObjectifOperationnelRules(?int $ignoreId = null): array
    {
        $codeRule = Rule::unique('pao_objectifs_operationnels', 'code')
            ->where(function ($query) {
                return $query->where(
                    'pao_objectif_strategique_id',
                    $this->input('pao_objectif_strategique_id')
                );
            });

        if ($ignoreId !== null) {
            $codeRule = $codeRule->ignore($ignoreId);
        }

        return [
            'pao_objectif_strategique_id' => ['required', 'integer', 'exists:pao_objectifs_strategiques,id'],
            'code' => ['required', 'string', 'max:30', $codeRule],
            'libelle' => ['required', 'string', 'max:255'],
            'description_action_detaillee' => ['required', 'string'],
            'responsable_id' => ['required', 'integer', 'exists:users,id'],
            'cible_pourcentage' => ['required', 'numeric', 'between:0,100'],
            'date_debut' => ['required', 'date', 'date_format:Y-m-d'],
            'date_fin' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:date_debut'],
            'statut_realisation' => ['required', Rule::in([
                'non_demarre',
                'en_cours',
                'en_retard',
                'bloque',
                'termine',
                'annule',
            ])],
            'ressources_requises' => ['nullable', 'string'],
            'indicateur_performance' => ['required', 'string', 'max:255'],
            'risques_potentiels' => ['nullable', 'string'],
            'echeance' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_fin'],
            'priorite' => ['required', Rule::in(['basse', 'moyenne', 'haute', 'critique'])],
            'progression_pourcentage' => ['required', 'integer', 'between:0,100'],
            'date_realisation' => [
                'nullable',
                'date',
                'date_format:Y-m-d',
                'after_or_equal:date_debut',
                'before_or_equal:today',
            ],
            'livrable_attendu' => ['nullable', 'string'],
            'contraintes' => ['nullable', 'string'],
            'dependances' => ['nullable', 'string'],
            'observations' => ['nullable', 'string'],
            'ordre' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function paoObjectifOperationnelMessages(): array
    {
        return [
            'code.unique' => 'Le code est deja utilise pour cet objectif strategique.',
            'cible_pourcentage.between' => 'La cible doit etre comprise entre 0 et 100.',
            'progression_pourcentage.between' => 'La progression doit etre comprise entre 0 et 100.',
            'date_fin.after_or_equal' => 'La date de fin doit etre superieure ou egale a la date de debut.',
            'echeance.after_or_equal' => 'L echeance doit etre superieure ou egale a la date de fin.',
            'date_realisation.after_or_equal' => 'La date de realisation doit etre superieure ou egale a la date de debut.',
            'date_realisation.before_or_equal' => 'La date de realisation ne peut pas etre dans le futur.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function paoObjectifOperationnelAttributes(): array
    {
        return [
            'pao_objectif_strategique_id' => 'objectif strategique',
            'description_action_detaillee' => 'description de l action detaillee',
            'responsable_id' => 'responsable de la tache',
            'cible_pourcentage' => 'cible en pourcentage',
            'date_debut' => 'date de debut',
            'date_fin' => 'date de fin',
            'statut_realisation' => 'statut de realisation',
            'ressources_requises' => 'ressources requises',
            'indicateur_performance' => 'indicateur de performance',
            'risques_potentiels' => 'risques potentiels',
            'progression_pourcentage' => 'progression en pourcentage',
            'date_realisation' => 'date de realisation',
        ];
    }

    protected function applyPaoObjectifOperationnelBusinessRules(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $statut = (string) $this->input('statut_realisation');
            $progression = (int) $this->input('progression_pourcentage');
            $dateRealisation = $this->input('date_realisation');

            if ($statut === 'non_demarre' && $progression !== 0) {
                $validator->errors()->add(
                    'progression_pourcentage',
                    'La progression doit etre 0 quand le statut est non_demarre.'
                );
            }

            if (in_array($statut, ['en_cours', 'en_retard', 'bloque'], true)
                && ($progression < 1 || $progression > 99)
            ) {
                $validator->errors()->add(
                    'progression_pourcentage',
                    'La progression doit etre entre 1 et 99 pour un statut en execution.'
                );
            }

            if ($statut === 'termine' && $progression !== 100) {
                $validator->errors()->add(
                    'progression_pourcentage',
                    'La progression doit etre a 100 quand le statut est termine.'
                );
            }

            if ($statut === 'termine' && $dateRealisation === null) {
                $validator->errors()->add(
                    'date_realisation',
                    'La date de realisation est obligatoire quand le statut est termine.'
                );
            }

            if ($statut !== 'termine' && $dateRealisation !== null) {
                $validator->errors()->add(
                    'date_realisation',
                    'La date de realisation ne doit etre renseignee que pour un statut termine.'
                );
            }

            if ($statut === 'annule' && $progression === 100) {
                $validator->errors()->add(
                    'progression_pourcentage',
                    'Une tache annulee ne peut pas avoir une progression de 100.'
                );
            }
        });
    }

    protected function resolveCurrentObjectifOperationnelId(): ?int
    {
        $routeValue = $this->route('paoObjectifOperationnel')
            ?? $this->route('pao_objectif_operationnel')
            ?? $this->route('objectifOperationnel')
            ?? $this->route('objectif_operationnel')
            ?? $this->input('id');

        if (is_object($routeValue) && method_exists($routeValue, 'getKey')) {
            return (int) $routeValue->getKey();
        }

        if (is_numeric($routeValue)) {
            return (int) $routeValue;
        }

        return null;
    }
}

