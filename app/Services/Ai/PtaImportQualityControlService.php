<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PtaImportQualityControlService
{
    public function __construct(
        private readonly PtaAgentResolverService $agents,
        private readonly PtaImportTemplateAnalyzerService $template
    ) {}

    /**
     * @param  array<string,mixed>  $row
     * @return array{valid:bool,score:int,errors:list<string>,warnings:list<string>,normalized:array<string,mixed>}
     */
    public function validateImportGlobalRow(array $row): array
    {
        $normalized = $this->normalizeRow($row);
        $errors = [];
        $warnings = [];

        foreach ($this->template->constraints()['required'] as $field) {
            if ($this->blank($normalized[$field] ?? null)) {
                $errors[] = $this->label($field).' obligatoire.';
            }
        }

        if ($this->blank($normalized['service_unite'] ?? null)) {
            $warnings[] = 'Service/unite recommande pour rattacher correctement le PTA.';
        }

        $agentCheck = $this->agents->verifyCodes($normalized['codes_agents_rmo'] ?? null);
        if (! $this->blank($normalized['rmo_raw'] ?? null) && $this->blank($normalized['codes_agents_rmo'] ?? null)) {
            $errors[] = 'RMO detecte mais aucun code_agent resolu.';
        }
        if ($agentCheck['invalid'] !== []) {
            $errors[] = 'Code agent introuvable : '.implode(', ', $agentCheck['invalid']).'.';
        }

        foreach (['date_debut_action', 'date_fin_action', 'date_echeance_objectif_strategique', 'date_echeance_objectif_operationnel'] as $dateField) {
            if (! $this->blank($normalized[$dateField] ?? null) && $this->normalizeDate($normalized[$dateField]) === null) {
                $errors[] = $this->label($dateField).' invalide.';
            }
        }

        if (! $this->blank($normalized['date_debut_action'] ?? null) && ! $this->blank($normalized['date_fin_action'] ?? null)) {
            $start = $this->normalizeDate($normalized['date_debut_action']);
            $end = $this->normalizeDate($normalized['date_fin_action']);
            if ($start instanceof Carbon && $end instanceof Carbon && $end->lt($start)) {
                $errors[] = 'Date fin action anterieure a la date debut action.';
            }
        }

        $target = $normalized['cible_minimum_execution'] ?? null;
        if (! $this->blank($target) && (! is_numeric($target) || (float) $target < 0 || (float) $target > 100)) {
            $errors[] = 'Cible minimum execution doit etre comprise entre 0 et 100.';
        }

        $type = strtoupper(trim((string) ($normalized['type_action'] ?? '')));
        if (! in_array($type, ['', 'Q', 'NQ', 'M'], true)) {
            $errors[] = 'Type action non autorise.';
        }
        if ($type === 'Q' && ($this->blank($normalized['quantite_cible'] ?? null) || $this->blank($normalized['unite_cible'] ?? null))) {
            $errors[] = 'Quantite cible et unite cible obligatoires pour une action quantitative.';
        }
        if ($type === 'M' && $this->blank($normalized['sous_actions'] ?? null)) {
            $errors[] = 'Sous-actions obligatoires pour une action composee.';
        }

        $seuilMode = $this->key((string) ($normalized['seuil_mode'] ?? ''));
        if (! in_array($seuilMode, ['', 'unique', 'trimestriel'], true)) {
            $errors[] = 'Seuil mode non autorise.';
        }
        if ($seuilMode === 'trimestriel') {
            foreach (['seuil_t1', 'seuil_t2', 'seuil_t3', 'seuil_t4'] as $field) {
                if ($this->blank($normalized[$field] ?? null)) {
                    $errors[] = $this->label($field).' obligatoire en mode trimestriel.';
                }
            }
        }

        $risk = $this->key((string) ($normalized['niveau_risque'] ?? ''));
        if (! in_array($risk, ['', 'faible', 'modere', 'eleve', 'critique'], true)) {
            $errors[] = 'Niveau risque non autorise.';
        }

        $financing = (string) ($normalized['financement'] ?? '');
        if (! in_array($financing, ['', '0', '1'], true)) {
            $errors[] = 'Financement accepte uniquement 0 ou 1.';
        }
        if ($financing === '1') {
            if ($this->blank($normalized['nature_financement'] ?? null)) {
                $errors[] = 'Nature financement obligatoire si financement = 1.';
            }
            if ($this->blank($normalized['montant_financement'] ?? null) || ! is_numeric($normalized['montant_financement'])) {
                $errors[] = 'Montant financement numerique obligatoire si financement = 1.';
            }
        }

        if ($this->blank($normalized['libelle_axe'] ?? null)) {
            $warnings[] = 'Axe strategique manquant.';
        }
        if ($this->blank($normalized['libelle_objectif_operationnel'] ?? null)) {
            $warnings[] = 'Objectif operationnel manquant.';
        }

        return [
            'valid' => $errors === [],
            'score' => $this->score($errors, $warnings),
            'errors' => $errors,
            'warnings' => $warnings,
            'normalized' => $normalized,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{total:int,valid:int,invalid:int,rows:list<array<string,mixed>>}
     */
    public function validateRows(array $rows): array
    {
        $stats = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'rows' => []];

        foreach ($rows as $row) {
            $stats['total']++;
            $validation = $this->validateImportGlobalRow($row);
            $stats[$validation['valid'] ? 'valid' : 'invalid']++;
            $stats['rows'][] = $validation;
        }

        return $stats;
    }

    public function normalizeDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(20\d{2})\s*[-\/]\s*(20\d{2})$/', $value, $matches) === 1) {
            return Carbon::create((int) $matches[2], 12, 31)->startOfDay();
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

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $columns = $this->template->columns();
        $normalized = array_fill_keys($columns, null);

        foreach ($row as $field => $value) {
            $target = $this->officialColumn((string) $field, $columns);
            if ($target === null) {
                $normalized[$field] = $value;

                continue;
            }

            $normalized[$target] = $this->normalizeValue($target, $value);
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $columns
     */
    private function officialColumn(string $field, array $columns): ?string
    {
        $key = $this->key($field);
        foreach ($columns as $column) {
            if ($this->key($column) === $key) {
                return $column;
            }
        }

        return null;
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '') {
            return null;
        }

        if (Str::startsWith($field, 'date_')) {
            $date = $this->normalizeDate($value);

            return $date?->format('Y-m-d') ?? $value;
        }

        if (in_array($field, ['financement', 'commentaire_obligatoire', 'champ_difficulte'], true)) {
            return in_array((string) $value, ['1', 'oui', 'Oui', 'true', 'vrai'], true) ? '1' : (in_array((string) $value, ['0', 'non', 'Non', 'false', 'faux'], true) ? '0' : $value);
        }

        if ($field === 'niveau_risque') {
            $risk = $this->key((string) $value);

            return match ($risk) {
                'modere' => 'modere',
                'eleve' => 'eleve',
                default => $value,
            };
        }

        if ($field === 'type_action') {
            return strtoupper((string) $value);
        }

        if ($field === 'cible_minimum_execution' && is_string($value)) {
            $number = str_replace(['%', ' ', "\u{00A0}"], '', $value);
            $number = str_replace(',', '.', $number);

            return is_numeric($number) ? (float) $number : $value;
        }

        return $value;
    }

    private function score(array $errors, array $warnings): int
    {
        return max(0, min(100, 100 - (count($errors) * 25) - (count($warnings) * 8)));
    }

    private function blank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    private function key(string $value): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
