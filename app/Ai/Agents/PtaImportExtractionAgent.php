<?php

namespace App\Ai\Agents;

use App\Services\Ai\AiPromptService;
use App\Services\Imports\PlanningExcelImportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class PtaImportExtractionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly AiPromptService $prompts
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->prompts->ptaExtractionSystemPrompt();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'rows' => $schema->array()
                ->items($schema->object($this->rowSchema($schema)))
                ->required(),
            'log' => $schema->array()
                ->items($schema->object([
                    'ligne_import' => $this->scalar($schema)->required(),
                    'page_pdf' => $this->scalar($schema)->required(),
                    'direction' => $this->scalar($schema)->required(),
                    'service_unite' => $this->scalar($schema)->required(),
                    'axe' => $this->scalar($schema)->required(),
                    'objectif_strategique' => $this->scalar($schema)->required(),
                    'objectif_operationnel' => $this->scalar($schema)->required(),
                    'ordre_action' => $this->scalar($schema)->required(),
                    'libelle_action' => $this->scalar($schema)->required(),
                    'etat_pdf' => $this->scalar($schema)->required(),
                    'score_confiance_ia' => $this->scalar($schema)->required(),
                    'note_normalisation' => $this->scalar($schema)->required(),
                ]))
                ->required(),
        ];
    }

    /**
     * @return array<string,Type>
     */
    private function rowSchema(JsonSchema $schema): array
    {
        $fields = [];

        foreach (PlanningExcelImportService::IMPORT_COLUMNS as $column) {
            $fields[$column] = $this->scalar($schema)->required();
        }

        foreach ([
            'ligne_import',
            'page_pdf',
            'score_confiance_ia',
            'note_normalisation',
            'etat_pdf',
            'etat_realisation_initial',
            'rmo_raw',
        ] as $column) {
            $fields[$column] = $this->scalar($schema)->required();
        }

        return $fields;
    }

    private function scalar(JsonSchema $schema): Type
    {
        return $schema->union(['string', 'integer', 'number', 'boolean'])->nullable();
    }
}
