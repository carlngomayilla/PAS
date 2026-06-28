<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class PtaDocumentStructureExtractorService
{
    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $metadata
     * @return array{document:array<string,mixed>,items:list<array<string,mixed>>}
     */
    public function extractFromRows(array $rows, array $metadata = []): array
    {
        $document = array_merge([
            'type_document' => $metadata['type_document'] ?? 'PTA',
            'annee' => $metadata['annee'] ?? null,
            'annee_debut_pas' => $metadata['annee_debut_pas'] ?? null,
            'annee_fin_pas' => $metadata['annee_fin_pas'] ?? null,
            'direction' => $metadata['direction'] ?? null,
            'service_unite' => $metadata['service_unite'] ?? null,
            'responsable' => $metadata['responsable'] ?? null,
            'fonction_responsable' => $metadata['fonction_responsable'] ?? null,
        ], $metadata);

        $context = [
            'ordre_axe' => null,
            'libelle_axe' => null,
            'ordre_objectif_strategique' => null,
            'libelle_objectif_strategique' => null,
            'ordre_objectif_operationnel' => null,
            'libelle_objectif_operationnel' => null,
        ];

        $items = [];
        $actionOrder = 0;
        foreach ($rows as $row) {
            $normalized = $this->normalizeKeys($row);
            $context = array_merge($context, array_filter([
                'ordre_axe' => $normalized['ordre_axe'] ?? $this->orderFrom($normalized['axe_strategique'] ?? null),
                'libelle_axe' => $normalized['libelle_axe'] ?? $normalized['axe_strategique'] ?? null,
                'ordre_objectif_strategique' => $normalized['ordre_objectif_strategique'] ?? $this->orderFrom($normalized['objectif_strategique'] ?? null),
                'libelle_objectif_strategique' => $normalized['libelle_objectif_strategique'] ?? $normalized['objectif_strategique'] ?? null,
                'ordre_objectif_operationnel' => $normalized['ordre_objectif_operationnel'] ?? $this->orderFrom($normalized['objectif_operationnel'] ?? null),
                'libelle_objectif_operationnel' => $normalized['libelle_objectif_operationnel'] ?? $normalized['objectif_operationnel'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && trim((string) $value) !== ''));

            $action = $normalized['libelle_action']
                ?? $normalized['description_actions_detaillees']
                ?? $normalized['description_des_actions_detaillees']
                ?? $normalized['actions_detaillees']
                ?? $normalized['action']
                ?? null;

            if ($action === null || trim((string) $action) === '') {
                continue;
            }

            $actionOrder++;
            $items[] = array_merge($context, [
                'ordre_action' => $normalized['ordre_action'] ?? $actionOrder,
                'libelle_action' => $action,
                'actions_detaillees' => $normalized['actions_detaillees'] ?? $action,
                'rmo_raw' => $normalized['rmo'] ?? $normalized['responsable'] ?? null,
                'cible' => $normalized['cible'] ?? null,
                'date_debut_action' => $normalized['debut'] ?? $normalized['date_debut'] ?? $normalized['date_debut_action'] ?? null,
                'date_fin_action' => $normalized['fin'] ?? $normalized['date_fin'] ?? $normalized['date_fin_action'] ?? null,
                'etat_realisation_initial' => $normalized['etat_de_realisation'] ?? $normalized['etat_realisation'] ?? null,
                'ressources_requises' => $normalized['ressources_requises'] ?? null,
                'indicateurs_performance' => $normalized['indicateurs_de_performance'] ?? $normalized['indicateurs_performance'] ?? null,
                'risques_potentiels' => $normalized['risques_potentiels'] ?? null,
                'confidence_score' => 0.78,
                'validation_warnings' => [],
            ]);
        }

        return ['document' => $this->completeYears($document, $items), 'items' => $items];
    }

    /**
     * @return array{document:array<string,mixed>,items:list<array<string,mixed>>}
     */
    public function extractFromText(string $text): array
    {
        $documentType = $this->detectDocumentType($text);
        $years = $this->yearsFrom($text);

        return [
            'document' => [
                'type_document' => $documentType,
                'annee' => $years['annee'],
                'annee_debut_pas' => $years['annee_debut_pas'],
                'annee_fin_pas' => $years['annee_fin_pas'],
                'direction' => $this->lineAfter($text, 'Direction'),
                'service_unite' => $this->lineAfter($text, 'Service'),
                'responsable' => null,
                'fonction_responsable' => null,
            ],
            'items' => [],
        ];
    }

    private function detectDocumentType(string $text): string
    {
        $key = $this->key($text);

        return match (true) {
            Str::contains($key, 'plan de travail annuel') || preg_match('/\bpta\b/i', $text) === 1 => 'PTA',
            Str::contains($key, 'plan d actions operationnel') || preg_match('/\bpao\b/i', $text) === 1 => 'PAO',
            Str::contains($key, 'plan d actions strategique') || preg_match('/\bpas\b/i', $text) === 1 => 'PAS',
            default => 'UNKNOWN',
        };
    }

    /**
     * @return array{annee:int|null,annee_debut_pas:int|null,annee_fin_pas:int|null}
     */
    private function yearsFrom(string $text): array
    {
        if (preg_match('/(20\d{2})\s*[-\/]\s*(20\d{2})/', $text, $range) === 1) {
            return [
                'annee' => (int) $range[1],
                'annee_debut_pas' => (int) $range[1],
                'annee_fin_pas' => (int) $range[2],
            ];
        }

        if (preg_match('/\b(20\d{2})\b/', $text, $year) === 1) {
            return [
                'annee' => (int) $year[1],
                'annee_debut_pas' => (int) $year[1],
                'annee_fin_pas' => (int) $year[1],
            ];
        }

        return ['annee' => null, 'annee_debut_pas' => null, 'annee_fin_pas' => null];
    }

    private function lineAfter(string $text, string $label): ?string
    {
        if (preg_match('/'.$label.'\s*[:\-]\s*(.+)$/im', $text, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeKeys(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[$this->key((string) $key, '_')] = $value;
        }

        return $normalized;
    }

    private function orderFrom(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/\b(\d+)\b/', (string) $value, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/\b([IVXLCDM]+)\b/u', (string) $value, $matches) !== 1) {
            return null;
        }

        return $this->romanToInt($matches[1]);
    }

    private function romanToInt(string $roman): int
    {
        $values = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
        $total = 0;
        $previous = 0;
        foreach (array_reverse(str_split(strtoupper($roman))) as $letter) {
            $value = $values[$letter] ?? 0;
            $total += $value < $previous ? -$value : $value;
            $previous = max($previous, $value);
        }

        return $total;
    }

    /**
     * @param  array<string,mixed>  $document
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function completeYears(array $document, array $items): array
    {
        $document['annee'] ??= $items[0]['annee'] ?? null;
        $document['annee_debut_pas'] ??= $document['annee'] ?? 2026;
        $document['annee_fin_pas'] ??= max((int) ($document['annee_debut_pas'] ?? 2026), (int) ($document['annee_fin_pas'] ?? $document['annee_debut_pas'] ?? 2026));

        return $document;
    }

    private function key(string $value, string $separator = ' '): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', $separator, $value) ?? $value;

        return trim($value, $separator);
    }
}
