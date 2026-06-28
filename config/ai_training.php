<?php

return [
    'knowledge_root' => env('AI_KNOWLEDGE_ROOT', storage_path('app/ai/knowledge')),
    'training_root' => env('AI_TRAINING_ROOT', storage_path('app/ai/training')),

    'pta' => [
        'agent_reference_path' => env('AI_PTA_AGENT_REFERENCE_PATH', base_path('docs/codes_agent_anbg_import.xlsx')),
        'import_template_path' => env('AI_PTA_IMPORT_TEMPLATE_PATH', base_path('docs/modele_import_global_pas_pao_pta.xlsx')),
        'pdf_text_command' => env('AI_PTA_PDF_TEXT_COMMAND'),
        'pdf_ocr_command' => env('AI_PTA_PDF_OCR_COMMAND'),
        'windows_ocr_enabled' => (bool) env('AI_PTA_WINDOWS_OCR_ENABLED', true),
        'windows_ocr_script_path' => env('AI_PTA_WINDOWS_OCR_SCRIPT_PATH', base_path('scripts/ocr/windows_pdf_ocr.ps1')),
        'windows_ocr_max_pages' => (int) env('AI_PTA_WINDOWS_OCR_MAX_PAGES', 0),
        'windows_ocr_render_width' => (int) env('AI_PTA_WINDOWS_OCR_RENDER_WIDTH', 2600),
        'windows_ocr_timeout' => (int) env('AI_PTA_WINDOWS_OCR_TIMEOUT', 300),
        'embedding_dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 64),
        'chunk_size' => (int) env('AI_KNOWLEDGE_CHUNK_SIZE', 1800),
    ],
];
