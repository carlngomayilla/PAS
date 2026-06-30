<?php

namespace App\Services\Ai;

use App\Models\Action;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PtaActionParameterizationService
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *     type_action:string,
     *     justification_type:string,
     *     cible_minimum_execution:int|float,
     *     quantite_cible:float|null,
     *     unite_cible:string|null,
     *     seuil_mode:string,
     *     seuil_t1:int|null,
     *     seuil_t2:int|null,
     *     seuil_t3:int|null,
     *     seuil_t4:int|null,
     *     nombre_sous_actions:int,
     *     sous_actions:list<string>,
     *     niveau_risque:string,
     *     commentaire_obligatoire:int,
     *     champ_difficulte:int,
     *     validation_warnings:list<string>,
     *     confidence_score:float
     * }
     */
    public function parameterize(array $payload): array
    {
        $action = $this->firstString($payload, ['libelle_action', 'action_pta', 'action', 'activite', 'tache', 'description_action', 'actions_detaillees']) ?? '';
        $objective = $this->firstString($payload, ['libelle_objectif_operationnel', 'objectif_operationnel', 'objectif_operationnel_pao', 'programme', 'pao', 'objectif']) ?? '';
        $indicator = $this->firstString($payload, ['indicateur', 'indicateurs_performance', 'justificatif_attendu', 'livrables_attendus', 'livrable', 'preuve_attendue']) ?? '';
        $target = $this->firstString($payload, ['cible', 'cible_minimum_execution', 'valeur_cible', 'target_value']) ?? '';
        $resources = $this->firstString($payload, ['ressources_requises', 'ressources_materielles', 'ressources_techniques', 'equipements', 'main_oeuvre', 'autres_ressources', 'partenaires']) ?? '';
        $risk = $this->firstString($payload, ['risque', 'risques_potentiels', 'risque_potentiel']) ?? '';
        $startDate = $this->firstString($payload, ['date_debut_action', 'date_debut', 'debut']);
        $endDate = $this->firstString($payload, ['date_fin_action', 'date_fin', 'fin', 'echeance']);
        $durationDays = $this->durationDays($startDate, $endDate);

        $metricText = $this->joinText([$indicator, $target, $action]);
        $typeDecisionText = $indicator !== '' ? $indicator : $action;
        $contextText = $this->joinText([$action, $objective, $indicator, $target, $resources, $risk]);
        $existingSubActions = $this->subActionsFrom($this->first($payload, ['sous_actions', 'actions_detaillees']));

        $hasFormula = $this->hasFormulaSignal($typeDecisionText);
        $hasQuantitative = $this->hasQuantitativeSignal($typeDecisionText);
        $hasDeliverable = $this->hasDeliverableSignal($this->joinText([$indicator, $action, $target]));
        $hasHardComposite = $this->hasHardCompositeSignal($contextText);
        $hasSoftComplex = $this->hasSoftComplexSignal($contextText) || ($durationDays !== null && $durationDays > 90);

        $warnings = [];
        $explicitType = $this->importTypeCode($this->first($payload, ['type_action']));
        [$type, $justification] = $this->resolveType(
            $explicitType,
            $existingSubActions,
            $hasHardComposite,
            $hasSoftComplex,
            $hasQuantitative || $hasFormula,
            $hasDeliverable,
            $warnings
        );

        $minimumExecution = $this->minimumExecution($payload);
        [$quantity, $unit, $targetWarnings] = $this->targetAndUnit($payload, $type, $metricText, $target, $hasFormula);
        $warnings = array_merge($warnings, $targetWarnings);

        $subActions = [];
        if ($type === 'M') {
            $subActions = $existingSubActions !== []
                ? $existingSubActions
                : $this->generateSubActions($contextText);

            if ($subActions === []) {
                $warnings[] = 'Action composee a confirmer : aucune sous-action exploitable n a ete detectee.';
            }
        }

        [$thresholdMode, $thresholds] = $this->thresholds(
            $this->firstString($payload, ['seuil_mode']),
            $type,
            $durationDays,
            $startDate,
            $hasHardComposite || $hasSoftComplex
        );

        $riskLevel = $this->riskLevel($contextText, $risk, $resources);
        $requiresComment = in_array($riskLevel, ['eleve', 'critique'], true) || $type === 'M' || $warnings !== [];

        return [
            'type_action' => $type,
            'justification_type' => $justification,
            'cible_minimum_execution' => $minimumExecution,
            'quantite_cible' => $quantity,
            'unite_cible' => $unit,
            'seuil_mode' => $thresholdMode,
            'seuil_t1' => $thresholds[0],
            'seuil_t2' => $thresholds[1],
            'seuil_t3' => $thresholds[2],
            'seuil_t4' => $thresholds[3],
            'nombre_sous_actions' => count($subActions),
            'sous_actions' => $subActions,
            'niveau_risque' => $riskLevel,
            'commentaire_obligatoire' => $requiresComment ? 1 : 0,
            'champ_difficulte' => 1,
            'validation_warnings' => array_values(array_unique($warnings)),
            'confidence_score' => $this->confidenceScore($explicitType !== null, $type, $hasQuantitative, $hasDeliverable, $hasHardComposite, $warnings),
        ];
    }

    /**
     * @param  list<string|array<string,mixed>>  $subActions
     */
    public function stringifySubActions(array $subActions): ?string
    {
        $items = [];
        foreach ($subActions as $item) {
            $label = is_array($item) ? (string) ($item['libelle'] ?? '') : (string) $item;
            $label = trim($label);
            if ($label !== '') {
                $items[] = $label;
            }
        }

        return $items === [] ? null : implode(' ; ', $items);
    }

    public function modelType(string $code): string
    {
        return match ($this->importTypeCode($code)) {
            'Q' => Action::TYPE_QUANTITATIVE,
            'M' => Action::TYPE_COMPOSEE,
            default => Action::TYPE_NON_QUANTITATIVE,
        };
    }

    public function modelMode(string $code): string
    {
        return match ($this->importTypeCode($code)) {
            'Q' => Action::MODE_QUANTITATIF,
            'M' => Action::MODE_SOUS_ACTIONS,
            default => Action::MODE_SANS_QUANTITE,
        };
    }

    private function resolveType(
        ?string $explicitType,
        array $existingSubActions,
        bool $hasHardComposite,
        bool $hasSoftComplex,
        bool $hasQuantitative,
        bool $hasDeliverable,
        array &$warnings
    ): array {
        if ($explicitType !== null) {
            return [$explicitType, 'Type repris depuis la ligne source ou la correction humaine.'];
        }

        if (count($existingSubActions) > 1) {
            return ['M', 'Plusieurs sous-actions sont deja presentes dans la ligne source.'];
        }

        if ($hasHardComposite) {
            return ['M', 'L action correspond a un chantier compose avec plusieurs etapes ou validations.'];
        }

        if ($hasQuantitative) {
            return ['Q', 'L indicateur contient une mesure, un taux, une quantite ou une formule de suivi.'];
        }

        if ($hasDeliverable && ! $hasSoftComplex) {
            return ['NQ', 'L action est validee par un livrable unique identifiable.'];
        }

        if ($hasSoftComplex) {
            return ['M', 'L action semble progressive ou etalee dans le temps et doit etre jalonnee.'];
        }

        if ($hasDeliverable) {
            return ['NQ', 'L indicateur attendu correspond a un livrable non quantitatif.'];
        }

        $warnings[] = 'Type ambigu : validation humaine recommandee avant import.';

        return ['M', 'Le contexte est ambigu ; une action composee limite le risque de suivi incomplet.'];
    }

    /**
     * @return array{0:float|null,1:string|null,2:list<string>}
     */
    private function targetAndUnit(array $payload, string $type, string $metricText, string $target, bool $hasFormula): array
    {
        if ($type !== 'Q') {
            return [null, null, []];
        }

        $warnings = [];
        $explicitQuantity = $this->numberFrom($this->first($payload, ['quantite_cible']));
        $explicitUnit = $this->firstString($payload, ['unite_cible', 'unite']);
        $unit = $explicitUnit ?: $this->inferUnit($metricText);
        $targetNumber = $this->numberFrom($target);
        $targetHasPercent = str_contains($target, '%');
        $ratioFormula = $hasFormula && preg_match('/\/|nombre\s+de.+nombre\s+de|x\s*100/i', Str::ascii($metricText)) === 1;

        $quantity = $explicitQuantity;
        if ($quantity === null && $targetNumber !== null && ! $ratioFormula) {
            if ($unit === '%' || ! $targetHasPercent) {
                $quantity = $targetNumber;
            }
        }

        if ($unit === null) {
            $warnings[] = 'Unite cible a confirmer pour l action quantitative.';
        }

        if ($quantity === null) {
            $warnings[] = 'Quantite cible exacte a confirmer : elle n est pas deductible sans inventer une valeur.';
        }

        return [$quantity, $unit, $warnings];
    }

    private function minimumExecution(array $payload): float|int
    {
        $value = $this->first($payload, ['cible_minimum_execution', 'cible']);
        $number = $this->numberFrom($value);

        if ($number === null) {
            return 100;
        }

        return max(0, min(100, $number));
    }

    /**
     * @return array{0:string,1:array{0:int|null,1:int|null,2:int|null,3:int|null}}
     */
    private function thresholds(?string $explicitMode, string $type, ?int $durationDays, ?string $startDate, bool $complex): array
    {
        $mode = $this->key((string) $explicitMode);
        if (! in_array($mode, ['unique', 'trimestriel'], true)) {
            $mode = ($type === 'M' || $complex || ($durationDays !== null && $durationDays > 60))
                ? 'trimestriel'
                : 'unique';
        }

        if ($mode !== 'trimestriel') {
            return ['unique', [null, null, null, null]];
        }

        $start = $this->dateFrom($startDate);
        if ($type === 'M' && $complex) {
            return ['trimestriel', [20, 50, 80, 100]];
        }

        if ($start instanceof Carbon && $start->month > 3) {
            return ['trimestriel', [10, 40, 70, 100]];
        }

        return ['trimestriel', [25, 50, 75, 100]];
    }

    /**
     * @return list<string>
     */
    private function generateSubActions(string $context): array
    {
        $key = $this->key($context);

        if (str_contains($key, 'politique de sauvegarde') || str_contains($key, 'sauvegarde des donnees')) {
            return [
                'Identifier les donnees critiques a sauvegarder',
                'Definir la frequence de sauvegarde',
                'Identifier les supports de stockage',
                'Rediger la politique de sauvegarde',
                'Valider la politique de sauvegarde',
                'Mettre en oeuvre les sauvegardes',
                'Tester la restauration des donnees',
            ];
        }

        if ($this->containsAny($key, ['developper', 'implementer', 'application', 'logiciel', 'glpi', 'controle interne'])) {
            return [
                'Rediger le cahier de charges',
                'Valider les besoins fonctionnels',
                'Developper ou configurer la solution',
                'Tester les fonctionnalites principales',
                'Mettre l application en production',
                'Former les utilisateurs',
                'Rediger le rapport de mise en oeuvre',
            ];
        }

        if ($this->containsAny($key, ['maintenance', 'maintenir'])) {
            return [
                'Identifier les equipements a maintenir',
                'Etablir le calendrier de maintenance',
                'Realiser la maintenance preventive',
                'Controler les interventions realisees',
                'Rediger le rapport de maintenance',
            ];
        }

        if ($this->containsAny($key, ['numeriser', 'numerisation', 'archives'])) {
            return [
                'Determiner le programme de numerisation',
                'Preparer les documents ou archives',
                'Numeriser les archives',
                'Controler les fichiers numerises',
                'Rediger le rapport de numerisation',
            ];
        }

        if ($this->containsAny($key, ['formation', 'former', 'renforcer les competences'])) {
            return [
                'Identifier les besoins en formation',
                'Organiser les formations retenues',
                'Derouler les formations',
                'Evaluer les formations',
                'Rediger le rapport de formation',
            ];
        }

        return [
            'Preparer l activite',
            'Executer l activite',
            'Valider les resultats',
            'Rediger le rapport de mise en oeuvre',
        ];
    }

    private function riskLevel(string $context, string $risk, string $resources): string
    {
        $text = $this->key($this->joinText([$context, $risk, $resources]));

        if ($this->containsAny($text, ['perte de donnees', 'securite', 'cyber', 'donnees critiques', 'panne majeure', 'defaillance du support', 'sauvegarde'])) {
            return 'critique';
        }

        if ($this->containsAny($text, ['budget', 'deficit', 'indisponibilite', 'retard', 'materiel', 'serveurs', 'scanner', 'plusieurs services', 'migration', 'difficultes techniques', 'manque de competences'])) {
            return 'eleve';
        }

        if ($resources !== '' || $risk !== '') {
            return 'modere';
        }

        if ($this->containsAny($text, ['formation', 'organiser', 'programme', 'suivi'])) {
            return 'modere';
        }

        return 'faible';
    }

    private function confidenceScore(bool $explicitType, string $type, bool $quantitative, bool $deliverable, bool $composite, array $warnings): float
    {
        $score = 0.72;
        if ($explicitType) {
            $score += 0.12;
        }
        if (($type === 'Q' && $quantitative) || ($type === 'NQ' && $deliverable) || ($type === 'M' && $composite)) {
            $score += 0.12;
        }
        if ($warnings === []) {
            $score += 0.06;
        }

        $score -= min(0.25, count($warnings) * 0.05);

        return round(max(0.45, min(0.96, $score)), 2);
    }

    private function hasFormulaSignal(string $text): bool
    {
        $key = $this->key($text);

        return str_contains($text, '%')
            || preg_match('/\/|x\s*100/i', $text) === 1
            || $this->containsAny($key, ['taux', 'pourcentage', 'nombre realise nombre prevu', 'nombre de']);
    }

    private function hasQuantitativeSignal(string $text): bool
    {
        $key = $this->key($text);

        return str_contains($text, '%')
            || $this->containsAny($key, [
                'nombre de',
                'quantite',
                'volume',
                'taux',
                'pourcentage',
                'metrage',
                'liste complete',
                'equipements identifies',
                'documents traites',
                'licences critiques',
                'postes identifies',
                'dossiers',
                'articles',
                'formations organisees',
                'formations realisees',
                'contenus publies',
            ]);
    }

    private function hasDeliverableSignal(string $text): bool
    {
        return $this->containsAny($this->key($text), [
            'rapport',
            'fiche',
            'note',
            'pv',
            'proces verbal',
            'document soumis',
            'strategie validee',
            'strategie elaboree',
            'cahier de charges',
            'cahier de charge',
            'etude de faisabilite',
            'proposition',
            'expression de besoin',
            'arrete',
            'business plan',
            'mise en production',
            'objectifs definis',
        ]);
    }

    private function hasHardCompositeSignal(string $text): bool
    {
        $key = $this->key($text);

        return preg_match('/mettre en place.*(politique|application|sauvegarde|dispositif)/', $key) === 1
            || $this->containsAny($key, [
                'developper',
                'implementer',
                'application',
                'logiciel',
                'glpi',
                'migration',
                'maintenance',
                'numeriser',
                'numerisation',
                'reorganisation',
                'politique de sauvegarde',
                'acquerir',
                'acquisition',
            ]);
    }

    private function hasSoftComplexSignal(string $text): bool
    {
        return $this->containsAny($this->key($text), [
            'programme de suivi',
            'suivi periodique',
            'sur toute l annee',
            'formation',
            'organiser',
            'plan de communication',
            'vulgarisation',
            'plusieurs',
        ]);
    }

    private function inferUnit(string $text): ?string
    {
        $key = $this->key($text);

        $units = [
            'formation' => 'formations',
            'equipement' => 'equipements',
            'licence' => 'licences',
            'document' => 'documents',
            'dossier' => 'dossiers',
            'archive' => 'archives',
            'article' => 'articles',
            'poste' => 'postes',
            'agent' => 'agents',
            'service' => 'services',
            'contenu' => 'contenus',
            'rapport' => 'rapports',
            'fiche' => 'fiches',
        ];

        foreach ($units as $needle => $unit) {
            if (str_contains($key, $needle)) {
                return $unit;
            }
        }

        if (str_contains($text, '%') || $this->containsAny($key, ['taux', 'pourcentage'])) {
            return '%';
        }

        return null;
    }

    private function importTypeCode(mixed $value): ?string
    {
        $key = $this->key((string) $value);

        return match ($key) {
            'q', 'quantitative', 'quantitatif' => 'Q',
            'nq', 'non quantitative', 'non quantitatif', 'non_quantitative', 'nonquantitative' => 'NQ',
            'm', 'mixte', 'composee', 'compose', 'composite', 'sous actions' => 'M',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function subActionsFrom(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $raw
            )));
        }

        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }

        $items = [];
        foreach (preg_split('/\s*;\s*/', $text) ?: [] as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk !== '') {
                $items[] = preg_replace('/^\d+[\).]\s*/', '', $chunk) ?: $chunk;
            }
        }

        if (count($items) > 1) {
            return array_values(array_unique($items));
        }

        preg_match_all('/(?:^|\s)(?:\d+[\).]|-)\s*([^;\r\n]+?)(?=\s+(?:\d+[\).]|-)\s*|$)/u', $text, $matches);
        $numbered = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $matches[1] ?? []
        )));

        return count($numbered) > 1 ? $numbered : [];
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  list<string>  $keys
     */
    private function firstString(array $source, array $keys): ?string
    {
        $value = $this->first($source, $keys);

        if ($this->blank($value)) {
            return null;
        }

        if (is_array($value)) {
            $value = implode(' ', array_map(static fn (mixed $item): string => (string) $item, $value));
        }

        return trim((string) $value);
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

    /**
     * @param  list<string|null>  $parts
     */
    private function joinText(array $parts): string
    {
        return collect($parts)
            ->filter(fn (mixed $value): bool => ! $this->blank($value))
            ->map(fn (mixed $value): string => is_array($value) ? implode(' ', $value) : (string) $value)
            ->implode(' ');
    }

    private function numberFrom(mixed $value): ?float
    {
        if ($this->blank($value)) {
            return null;
        }

        $number = str_replace(['%', ' ', "\u{00A0}"], '', (string) $value);
        $number = str_replace(',', '.', $number);

        return is_numeric($number) ? (float) $number : null;
    }

    private function durationDays(?string $startDate, ?string $endDate): ?int
    {
        $start = $this->dateFrom($startDate);
        $end = $this->dateFrom($endDate);

        if (! $start instanceof Carbon || ! $end instanceof Carbon) {
            return null;
        }

        return max(0, $start->diffInDays($end));
    }

    private function dateFrom(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date instanceof Carbon && $date->format($format) === $value) {
                    return $date->startOfDay();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function blank(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }

    private function key(string $value): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9%]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
