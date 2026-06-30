<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class PtaAiImportNormalizerService
{
    public function __construct(
        private readonly PtaAgentResolverService $agents,
        private readonly PtaImportTemplateAnalyzerService $template,
        private readonly PtaImportQualityControlService $quality,
        private readonly PtaActionParameterizationService $parameterizer
    ) {}

    /**
     * @param  array<string,mixed>  $document
     * @param  array<string,mixed>  $item
     * @return array{row:array<string,mixed>,validation:array<string,mixed>,agent_resolution:array<string,mixed>}
     */
    public function normalizeItem(array $document, array $item): array
    {
        $row = array_fill_keys($this->template->columns(), null);
        $source = array_merge($document, $item);

        $rmoRaw = $this->first($source, ['codes_agents_rmo', 'rmo_raw', 'responsable', 'rmo']);
        $agentResolution = $this->agents->resolve(
            is_array($rmoRaw) ? $rmoRaw : ($rmoRaw === null ? null : (string) $rmoRaw),
            $this->string($this->first($source, ['direction'])),
            $this->string($this->first($source, ['service_unite', 'service']))
        );

        $subActions = $this->subActionsFrom($source);

        $row['annee_debut_pas'] = $this->first($source, ['annee_debut_pas', 'annee_debut', 'annee']) ?? 2026;
        $row['annee_fin_pas'] = $this->first($source, ['annee_fin_pas', 'annee_fin']) ?? $row['annee_debut_pas'];
        $row['ordre_axe'] = $this->first($source, ['ordre_axe', 'axe_numero', 'numero_axe']);
        $row['libelle_axe'] = $this->first($source, ['libelle_axe', 'axe_strategique', 'axe', 'pas_axe']);
        $row['ordre_objectif_strategique'] = $this->first($source, ['ordre_objectif_strategique', 'objectif_strategique_numero', 'numero_objectif_strategique']);
        $row['libelle_objectif_strategique'] = $this->first($source, ['libelle_objectif_strategique', 'objectif_strategique', 'objectif_strategique_pas']);
        $row['date_echeance_objectif_strategique'] = $this->first($source, ['date_echeance_objectif_strategique', 'echeance_objectif_strategique', 'echeance_pas']);
        $row['direction'] = $this->first($source, ['direction', 'direction_responsable', 'direction_concernee', 'entite']);
        $row['service_unite'] = $this->first($source, ['service_unite', 'service', 'service_responsable', 'service_concerne', 'unite']);
        $row['ordre_objectif_operationnel'] = $this->first($source, ['ordre_objectif_operationnel', 'objectif_operationnel_numero', 'numero_objectif_operationnel']);
        $row['libelle_objectif_operationnel'] = $this->first($source, ['libelle_objectif_operationnel', 'objectif_operationnel', 'objectif_operationnel_pao', 'pao', 'programme']);
        $row['date_echeance_objectif_operationnel'] = $this->first($source, ['date_echeance_objectif_operationnel', 'echeance_objectif_operationnel', 'echeance']);
        $row['ordre_action'] = $this->first($source, ['ordre_action', 'action_numero']) ?? 1;
        $row['libelle_action'] = $this->first($source, ['libelle_action', 'action_pta', 'description_action', 'action', 'activite', 'tache', 'actions_detaillees']);
        $row['date_debut_action'] = $this->first($source, ['date_debut_action', 'date_debut', 'debut']);
        $row['date_fin_action'] = $this->first($source, ['date_fin_action', 'date_fin', 'fin', 'echeance']);
        $row['codes_agents_rmo'] = $this->agents->codesToString($agentResolution['codes']);
        $row['cible_minimum_execution'] = $this->targetFrom($this->first($source, ['cible_minimum_execution', 'cible', 'valeur_cible', 'target_value', 'target']));
        $row['justificatif_attendu'] = $this->first($source, ['justificatif_attendu', 'indicateurs_performance', 'indicateur', 'livrable', 'preuve_attendue']);
        $row['risque'] = $this->first($source, ['risque', 'risques_potentiels']);
        $row['mesures_preventives'] = $this->first($source, ['mesures_preventives']);
        $row['ressources_materielles'] = $this->first($source, ['ressources_materielles', 'ressources_requises', 'ressources_techniques', 'equipements']);
        $row['main_oeuvre'] = $this->first($source, ['main_oeuvre']);
        $row['autres_ressources'] = $this->first($source, ['autres_ressources']);
        $row['financement'] = $this->first($source, ['financement']) ?? 0;
        $row['nature_financement'] = $this->first($source, ['nature_financement', 'source_financement']);
        $row['montant_financement'] = $this->first($source, ['montant_financement', 'budget_previsionnel', 'budget', 'montant', 'cout']);

        $parameterization = $this->parameterizer->parameterize(array_merge($source, [
            'libelle_action' => $row['libelle_action'],
            'indicateur' => $row['justificatif_attendu'],
            'cible_minimum_execution' => $row['cible_minimum_execution'],
            'date_debut_action' => $row['date_debut_action'],
            'date_fin_action' => $row['date_fin_action'],
            'risque' => $row['risque'],
            'ressources_requises' => $row['ressources_materielles'],
            'sous_actions' => $subActions,
        ]));

        $row['type_action'] = $this->first($source, ['type_action']) ?? $parameterization['type_action'];
        $row['quantite_cible'] = $this->first($source, ['quantite_cible']) ?? $parameterization['quantite_cible'];
        $row['unite_cible'] = $this->first($source, ['unite_cible', 'unite']) ?? $parameterization['unite_cible'];
        $row['seuil_mode'] = $this->first($source, ['seuil_mode']) ?? $parameterization['seuil_mode'];
        $row['seuil_t1'] = $this->first($source, ['seuil_t1']) ?? $parameterization['seuil_t1'];
        $row['seuil_t2'] = $this->first($source, ['seuil_t2']) ?? $parameterization['seuil_t2'];
        $row['seuil_t3'] = $this->first($source, ['seuil_t3']) ?? $parameterization['seuil_t3'];
        $row['seuil_t4'] = $this->first($source, ['seuil_t4']) ?? $parameterization['seuil_t4'];
        $row['nombre_sous_actions'] = $subActions === [] ? ($this->first($source, ['nombre_sous_actions']) ?? $parameterization['nombre_sous_actions']) : count($subActions);
        $row['sous_actions'] = $subActions === [] ? ($this->first($source, ['sous_actions']) ?? $this->parameterizer->stringifySubActions($parameterization['sous_actions'])) : implode(' ; ', $subActions);
        $row['niveau_risque'] = $this->first($source, ['niveau_risque']) ?? $parameterization['niveau_risque'];
        $row['commentaire_obligatoire'] = $this->first($source, ['commentaire_obligatoire']) ?? $parameterization['commentaire_obligatoire'];
        $row['champ_difficulte'] = $this->first($source, ['champ_difficulte']) ?? $parameterization['champ_difficulte'];
        $row['justification_type'] = $parameterization['justification_type'];
        $row['validation_warnings'] = implode(' | ', $parameterization['validation_warnings']);
        $row['confidence_score'] = $parameterization['confidence_score'];

        $row['rmo_raw'] = $rmoRaw;
        $row['etat_realisation_initial'] = $this->first($source, ['etat_realisation_initial', 'etat_realisation', 'statut_initial']);

        $validation = $this->quality->validateImportGlobalRow($row);

        return [
            'row' => $validation['normalized'],
            'validation' => $validation,
            'agent_resolution' => $agentResolution,
        ];
    }

    /**
     * @param  array<string,mixed>  $document
     * @param  list<array<string,mixed>>  $items
     * @return list<array{row:array<string,mixed>,validation:array<string,mixed>,agent_resolution:array<string,mixed>}>
     */
    public function normalizeItems(array $document, array $items): array
    {
        return array_map(fn (array $item): array => $this->normalizeItem($document, $item), $items);
    }

    /**
     * @param  array<string,mixed>  $source
     * @return list<string>
     */
    private function subActionsFrom(array $source): array
    {
        $raw = $this->first($source, ['sous_actions']);
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $raw
            )));
        }

        $text = $this->string($raw ?? $this->first($source, ['actions_detaillees']));
        if ($text === null) {
            return [];
        }

        preg_match_all('/(?:^|\s)(?:\d+[\).]|[-•])\s*([^;]+?)(?=\s+(?:\d+[\).]|[-•])\s*|$)/u', $text, $matches);
        $items = array_map(static fn (string $value): string => trim($value), $matches[1] ?? []);

        return count($items) > 1 ? array_values(array_filter($items)) : [];
    }

    private function targetFrom(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string) $value);
        $number = str_replace(['%', ' ', "\u{00A0}"], '', $text);
        $number = str_replace(',', '.', $number);

        return is_numeric($number) ? (float) $number : $value;
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  list<string>  $keys
     */
    private function first(array $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && ! $this->blank($source[$key])) {
                return $source[$key];
            }
        }

        $normalized = [];
        foreach ($source as $key => $value) {
            $normalized[$this->key((string) $key)] = $value;
        }

        foreach ($keys as $key) {
            $lookup = $this->key($key);
            if (array_key_exists($lookup, $normalized) && ! $this->blank($normalized[$lookup])) {
                return $normalized[$lookup];
            }
        }

        return null;
    }

    private function string(mixed $value): ?string
    {
        return $this->blank($value) ? null : trim((string) $value);
    }

    private function blank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }

    private function key(string $value): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
