<?php

return [
    'knowledge_root' => env('AI_KNOWLEDGE_ROOT', storage_path('app/ai/knowledge')),
    'training_root' => env('AI_TRAINING_ROOT', storage_path('app/ai/training')),

    'pta' => [
        'agent_reference_path' => env('AI_PTA_AGENT_REFERENCE_PATH', base_path('docs/codes_agent_anbg_import.xlsx')),
        'import_template_path' => env('AI_PTA_IMPORT_TEMPLATE_PATH', base_path('docs/modele_import_global_pas_pao_pta.xlsx')),
        'embedding_dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 64),
        'chunk_size' => (int) env('AI_KNOWLEDGE_CHUNK_SIZE', 1800),
    ],
];
