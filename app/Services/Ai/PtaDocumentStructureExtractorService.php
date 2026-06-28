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
        $ocrBoxes = $this->ocrBoxesFromText($text);
        if ($ocrBoxes !== []) {
            $structured = $this->extractFromOcrBoxes($text, $ocrBoxes);
            if (($structured['items'] ?? []) !== []) {
                return $structured;
            }
        }

        $documentType = $this->detectDocumentType($text);
        $years = $this->yearsFrom($text);
        $lines = $this->linesFrom($text);
        $context = [
            'ordre_axe' => null,
            'libelle_axe' => null,
            'ordre_objectif_strategique' => null,
            'libelle_objectif_strategique' => null,
            'ordre_objectif_operationnel' => null,
            'libelle_objectif_operationnel' => null,
        ];
        $pendingContext = null;
        $pendingOcrAction = null;
        $items = [];
        $actionOrder = 0;
        $inActionTable = false;

        foreach ($lines as $line) {
            $key = $this->key($line);
            if ($key === '' || $this->isIgnoredTextLine($key)) {
                continue;
            }

            if ($this->isActionHeaderLine($key)) {
                $this->flushPendingOcrAction($pendingOcrAction, $items, $context, $actionOrder);
                $inActionTable = true;
                $pendingContext = null;

                continue;
            }

            $detectedContext = $this->contextMarker($line);
            if ($detectedContext !== null) {
                $this->flushPendingOcrAction($pendingOcrAction, $items, $context, $actionOrder);
                [$field, $value, $order] = $detectedContext;
                if ($order !== null) {
                    $context[$this->orderFieldFor($field)] = $order;
                }

                if ($value === null || $value === '') {
                    $pendingContext = $field;
                } else {
                    $context[$field] = $this->cleanContextLabel($value);
                    $pendingContext = null;
                    if ($field === 'libelle_objectif_operationnel') {
                        $actionOrder = 0;
                    }
                }

                $inActionTable = false;

                continue;
            }

            if (is_string($pendingContext)) {
                $context[$pendingContext] = $this->cleanContextLabel($line);
                if ($pendingContext === 'libelle_objectif_operationnel') {
                    $actionOrder = 0;
                }
                $pendingContext = null;

                continue;
            }

            if (! $inActionTable) {
                continue;
            }

            $action = $this->actionFromTextTableLine($line);
            if ($action === null) {
                if ($this->isOcrActionTextLine($line)) {
                    if ($pendingOcrAction !== null && $this->startsNewAction($line)) {
                        $this->flushPendingOcrAction($pendingOcrAction, $items, $context, $actionOrder);
                    }

                    if ($pendingOcrAction === null && ! $this->startsNewAction($line)) {
                        continue;
                    }

                    $this->pushPendingOcrAction($pendingOcrAction, $line);
                }

                continue;
            }

            $this->flushPendingOcrAction($pendingOcrAction, $items, $context, $actionOrder);
            $actionOrder++;
            $items[] = array_merge($context, [
                'ordre_action' => $actionOrder,
                'libelle_action' => $action['libelle_action'],
                'actions_detaillees' => $action['libelle_action'],
                'rmo_raw' => $action['rmo_raw'],
                'cible' => $action['cible'],
                'date_debut_action' => $action['date_debut_action'],
                'date_fin_action' => $action['date_fin_action'],
                'etat_realisation_initial' => $action['etat_realisation_initial'],
                'ressources_requises' => $action['ressources_requises'],
                'indicateurs_performance' => $action['indicateurs_performance'],
                'risques_potentiels' => $action['risques_potentiels'],
                'confidence_score' => 0.72,
                'validation_warnings' => [],
            ]);
        }

        $this->flushPendingOcrAction($pendingOcrAction, $items, $context, $actionOrder);

        $document = [
            'type_document' => $documentType,
            'annee' => $years['annee'],
            'annee_debut_pas' => $years['annee_debut_pas'],
            'annee_fin_pas' => $years['annee_fin_pas'],
            'direction' => $this->lineAfter($text, 'Direction'),
            'service_unite' => $this->lineAfter($text, 'Service'),
            'responsable' => null,
            'fonction_responsable' => null,
        ];

        return [
            'document' => $this->completeYears($document, $items),
            'items' => $items,
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
     * @return list<string>
     */
    private function linesFrom(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split('/\n+/', $text) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            $lines
        ), static fn (string $line): bool => $line !== ''));
    }

    private function isIgnoredTextLine(string $key): bool
    {
        return Str::startsWith($key, 'anbg proposition')
            || Str::startsWith($key, 'ocr box')
            || Str::startsWith($key, 'ocr page')
            || Str::startsWith($key, 'page ')
            || Str::startsWith($key, 'plan de travail annuel')
            || Str::startsWith($key, 'plan d actions operationnel')
            || $key === 'axes strategiques'
            || $key === 'n'
            || $key === 'echeance';
    }

    /**
     * @param  list<array{page:int,x:int,y:int,text:string}>  $boxes
     * @return array{document:array<string,mixed>,items:list<array<string,mixed>>}
     */
    private function extractFromOcrBoxes(string $text, array $boxes): array
    {
        $documentType = $this->detectDocumentType($text);
        $years = $this->yearsFrom($text);
        $context = [
            'ordre_axe' => null,
            'libelle_axe' => null,
            'ordre_objectif_strategique' => null,
            'libelle_objectif_strategique' => null,
            'ordre_objectif_operationnel' => null,
            'libelle_objectif_operationnel' => null,
        ];
        $pendingContext = null;
        $pendingOcrAction = null;
        $items = [];
        $actionOrder = 0;

        foreach ($this->ocrBoxesByPage($boxes) as $pageBoxes) {
            $inActionTable = false;
            $actionColumnRight = null;
            $headerY = null;

            foreach ($pageBoxes as $box) {
                $line = $box['text'];
                $key = $this->key($line);
                if ($key === '' || $this->isIgnoredTextLine($key)) {
                    continue;
                }

                if ($this->isActionHeaderLine($key)) {
                    $this->flushPendingOcrAction($pendingOcrAction, $items, $context, $actionOrder);
                    $inActionTable = true;
                    $headerY = $box['y'];
                    $actionColumnRight = $this->ocrActionColumnRight($pageBoxes, $headerY);
                    $pendingContext = null;

                    continue;
                }

                $detectedContext = $this->contextMarker($line);
                if ($detectedContext !== null) {
                    $this->flushPendingOcrAction($pendingOcrAction, $items, $context, $actionOrder);
                    [$field, $value, $order] = $detectedContext;
                    if ($order !== null) {
                        $context[$this->orderFieldFor($field)] = $order;
                    }

                    if ($value === null || $value === '') {
                        $pendingContext = $field;
                    } else {
                        $context[$field] = $this->cleanContextLabel($value);
                        $pendingContext = null;
                        if ($field === 'libelle_objectif_operationnel') {
                            $actionOrder = 0;
                        }
                    }

                    $inActionTable = false;
                    $actionColumnRight = null;
                    $headerY = null;

                    continue;
                }

                if (is_string($pendingContext)) {
                    $context[$pendingContext] = $this->cleanContextLabel($line);
                    if ($pendingContext === 'libelle_objectif_operationnel') {
                        $actionOrder = 0;
                    }
                    $pendingContext = null;

                    continue;
                }

                if (! $inActionTable || $actionColumnRight === null || $headerY === null) {
                    continue;
                }

                if ($box['y'] <= $headerY + 28 || $box['x'] > $actionColumnRight) {
                    continue;
                }

                if (! $this->isOcrActionTextLine($line)) {
                    continue;
                }

                if ($pendingOcrAction !== null && $this->startsNewAction($line)) {
                    $this->flushPendingOcrAction($pendingOcrAction, $items, $context, $actionOrder);
                }

                if ($pendingOcrAction === null && ! $this->startsNewAction($line)) {
                    continue;
                }

                $this->pushPendingOcrAction($pendingOcrAction, $line);
            }

            $this->flushPendingOcrAction($pendingOcrAction, $items, $context, $actionOrder);
        }

        $document = [
            'type_document' => $documentType,
            'annee' => $years['annee'],
            'annee_debut_pas' => $years['annee_debut_pas'],
            'annee_fin_pas' => $years['annee_fin_pas'],
            'direction' => $this->lineAfter($text, 'Direction'),
            'service_unite' => $this->lineAfter($text, 'Service'),
            'responsable' => null,
            'fonction_responsable' => null,
        ];

        return [
            'document' => $this->completeYears($document, $items),
            'items' => $items,
        ];
    }

    /**
     * @return list<array{page:int,x:int,y:int,text:string}>
     */
    private function ocrBoxesFromText(string $text): array
    {
        preg_match_all('/^@@OCR_BOX\|(\d+)\|(\d+)\|(\d+)\|(.+)$/m', $text, $matches, PREG_SET_ORDER);

        return array_values(array_map(static fn (array $match): array => [
            'page' => (int) $match[1],
            'x' => (int) $match[2],
            'y' => (int) $match[3],
            'text' => trim((string) $match[4]),
        ], $matches));
    }

    /**
     * @param  list<array{page:int,x:int,y:int,text:string}>  $boxes
     * @return list<list<array{page:int,x:int,y:int,text:string}>>
     */
    private function ocrBoxesByPage(array $boxes): array
    {
        $pages = [];
        foreach ($boxes as $box) {
            $pages[$box['page']][] = $box;
        }

        ksort($pages);
        foreach ($pages as &$pageBoxes) {
            usort($pageBoxes, static fn (array $a, array $b): int => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
        }

        return array_values($pages);
    }

    /**
     * @param  list<array{page:int,x:int,y:int,text:string}>  $pageBoxes
     */
    private function ocrActionColumnRight(array $pageBoxes, int $headerY): int
    {
        $columnStarts = [];
        foreach ($pageBoxes as $box) {
            if (abs($box['y'] - $headerY) > 90) {
                continue;
            }

            $key = $this->key($box['text']);
            if (in_array($key, ['rmo', 'cible'], true) || Str::startsWith($key, 'debut')) {
                $columnStarts[] = $box['x'];
            }
        }

        if ($columnStarts !== []) {
            return max(0, min($columnStarts) - 25);
        }

        $maxX = max(array_map(static fn (array $box): int => $box['x'], $pageBoxes));

        return (int) max(500, $maxX * 0.35);
    }

    private function isActionHeaderLine(string $key): bool
    {
        return Str::contains($key, 'description des actions');
    }

    /**
     * @return array{0:string,1:string|null,2:int|null}|null
     */
    private function contextMarker(string $line): ?array
    {
        $key = $this->key($line);

        if (Str::startsWith($key, 'axe strategique')) {
            $value = trim(preg_replace('/^axe\s+strategique(?:\s+\d+)?\s*[:\-]?\s*/iu', '', $line) ?? '');

            return ['libelle_axe', $value === '' ? null : $value, $this->orderFrom($line)];
        }

        if (Str::startsWith($key, 'objectif strategique')) {
            $value = trim(preg_replace('/^objectif\s+strategique(?:\s+n\s*[^0-9A-Za-z\s]?)?\s*(?:\d+)?\s*[:\-]?\s*/iu', '', $line) ?? '');

            return ['libelle_objectif_strategique', $value === '' ? null : $value, $this->orderFrom($line)];
        }

        if (Str::startsWith($key, 'objectif operationnel')) {
            $value = trim(preg_replace('/^objectif\s+operationnel(?:\s+n\s*[^0-9A-Za-z\s]?)?\s*(?:\d+)?\s*[:\-]?\s*/iu', '', $line) ?? '');

            return ['libelle_objectif_operationnel', $value === '' ? null : $value, $this->orderFrom($line)];
        }

        return null;
    }

    private function orderFieldFor(string $labelField): string
    {
        return match ($labelField) {
            'libelle_axe' => 'ordre_axe',
            'libelle_objectif_strategique' => 'ordre_objectif_strategique',
            default => 'ordre_objectif_operationnel',
        };
    }

    private function cleanContextLabel(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^\(?[IVXLCDM]+\)?\s*[\).\-]\s*/iu', '', $value) ?? $value;
        $value = preg_replace('/^\d+\s*[\).\-]?\s*/', '', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function actionFromTextTableLine(string $line): ?array
    {
        $columns = $this->splitActionColumns($line);
        if (count($columns) < 5) {
            return null;
        }

        $columns = array_pad($columns, 9, null);
        $action = trim((string) $columns[0]);
        if ($action === '' || $this->isActionHeaderLine($this->key($action))) {
            return null;
        }

        return [
            'libelle_action' => $action,
            'rmo_raw' => $columns[1] ?? null,
            'cible' => $columns[2] ?? null,
            'date_debut_action' => $columns[3] ?? null,
            'date_fin_action' => $columns[4] ?? null,
            'etat_realisation_initial' => $columns[5] ?? null,
            'ressources_requises' => $columns[6] ?? null,
            'indicateurs_performance' => $columns[7] ?? null,
            'risques_potentiels' => $columns[8] ?? null,
        ];
    }

    /**
     * @param  array{parts:list<string>}|null  $pending
     */
    private function pushPendingOcrAction(?array &$pending, string $line): void
    {
        $line = $this->cleanOcrActionLine($line);
        if ($line === '') {
            return;
        }

        if ($pending === null) {
            $pending = ['parts' => [$line]];

            return;
        }

        $pending['parts'][] = $line;
    }

    /**
     * @param  array{parts:list<string>,flush?:bool}|null  $pending
     * @param  list<array<string,mixed>>  $items
     * @param  array<string,mixed>  $context
     */
    private function flushPendingOcrAction(?array &$pending, array &$items, array $context, int &$actionOrder): void
    {
        if ($pending === null || ($pending['parts'] ?? []) === []) {
            $pending = null;

            return;
        }

        $action = trim(implode(' ', $pending['parts']));
        $pending = null;

        if ($action === '' || mb_strlen($action) < 6) {
            return;
        }

        $actionOrder++;
        $items[] = array_merge($context, [
            'ordre_action' => $actionOrder,
            'libelle_action' => $action,
            'actions_detaillees' => $action,
            'rmo_raw' => null,
            'cible' => null,
            'date_debut_action' => null,
            'date_fin_action' => null,
            'etat_realisation_initial' => null,
            'ressources_requises' => null,
            'indicateurs_performance' => null,
            'risques_potentiels' => null,
            'confidence_score' => 0.58,
            'validation_warnings' => ['Action reconstruite depuis une sortie OCR sans colonnes completes.'],
        ]);
    }

    private function isOcrActionTextLine(string $line): bool
    {
        $key = $this->key($line);
        if ($key === '' || mb_strlen($key) < 3) {
            return false;
        }

        if (preg_match('/^(?:\d+|[ivxlcdm]+)$/i', $key) === 1) {
            return false;
        }

        if (preg_match('/^\d{1,2}[\/\-]/', $line) === 1 || str_contains($line, '%')) {
            return false;
        }

        if ($this->containsAny($key, [
            'rmo',
            'cible',
            'debut',
            'fin',
            'etat de',
            'realisation',
            'ressources',
            'requises',
            'indicateurs',
            'performance',
            'risques',
            'potentiels',
            'non demarre',
            'en cours',
            'axes strategiques',
            'objectifs strategiques',
            'objectifs operationnels',
            'echeance',
        ])) {
            return false;
        }

        return $this->startsNewAction($line)
            || preg_match('/^[a-zàâçéèêëîïôûùüÿñæœ]/u', trim($line)) === 1
            || str_word_count(Str::ascii($line)) >= 3;
    }

    private function startsNewAction(string $line): bool
    {
        $key = $this->key($line);
        $key = preg_replace('/^\d+\s*/', '', $key) ?? $key;

        return $this->containsAny($key, [
            'selectionner',
            'rediger',
            'detruire',
            'actualiser',
            'ctualiser',
            'proposer',
            'reposer',
            'determiner',
            'organiser',
            'evaluer',
            'definir',
            'derouler',
            'identifier',
            'developper',
            'migrer',
            'fournir',
            'etudier',
            'mettre en place',
            'faire',
            'effectuer',
            'participer',
            'acquerir',
            'promouvoir',
            'soumettre',
            'inviter',
            'etablir',
            'elaborer',
            'valider',
            'vulgariser',
            'vulgarisation',
            'associer',
            'aller au contact',
        ]);
    }

    private function cleanOcrActionLine(string $line): string
    {
        $line = trim($line);
        $line = preg_replace('/^\d+\s*[\).:-]?\s*/', '', $line) ?? $line;
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        return trim($line);
    }

    /**
     * @return list<string>
     */
    private function splitActionColumns(string $line): array
    {
        $line = trim($line);
        if (str_contains($line, '|')) {
            return $this->cleanColumns(explode('|', $line));
        }

        if (str_contains($line, "\t")) {
            return $this->cleanColumns(explode("\t", $line));
        }

        $columns = preg_split('/\s{2,}/', $line) ?: [];
        if (count($columns) >= 5) {
            return $this->cleanColumns($columns);
        }

        return [];
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function cleanColumns(array $columns): array
    {
        return array_values(array_filter(array_map(
            static fn (string $column): string => trim($column),
            $columns
        ), static fn (string $column): bool => $column !== ''));
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

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
