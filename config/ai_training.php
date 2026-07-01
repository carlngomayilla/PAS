<?php

return [
    'knowledge_root' => env('AI_KNOWLEDGE_ROOT', storage_path('app/ai/knowledge')),
    'training_root' => env('AI_TRAINING_ROOT', storage_path('app/ai/training')),

    'pta' => [
        'agent_reference_path' => env('AI_PTA_AGENT_REFERENCE_PATH', base_path('docs/codes_agent_anbg_import.xlsx')),
        'import_template_path' => env('AI_PTA_IMPORT_TEMPLATE_PATH', base_path('docs/modele_import_global_pas_pao_pta.xlsx')),
        'pdf_text_command' => env('AI_PTA_PDF_TEXT_COMMAND'),
        'pdf_ocr_command' => env('AI_PTA_PDF_OCR_COMMAND'),
        'ocr_engine' => env('AI_PTA_OCR_ENGINE', 'auto'),
        'paddleocr_command' => env('AI_PTA_PADDLEOCR_COMMAND'),
        'surya_ocr_command' => env('AI_PTA_SURYA_OCR_COMMAND'),
        'pdf_ocr_timeout' => (int) env('AI_PTA_PDF_OCR_TIMEOUT', 900),
        'pdf_min_text_chars' => (int) env('AI_PTA_PDF_MIN_TEXT_CHARS', 80),
        'pdf_parser_enabled' => (bool) env('AI_PTA_PDF_PARSER_ENABLED', true),
        'pdf_parser_max_bytes' => (int) env('AI_PTA_PDF_PARSER_MAX_BYTES', 5 * 1024 * 1024),
        'llm_enabled' => (bool) env('AI_PTA_LLM_ENABLED', true),
        'llm_provider' => env('AI_PTA_LLM_PROVIDER'),
        'llm_model' => env('AI_PTA_LLM_MODEL'),
        'llm_text_model' => env('AI_PTA_TEXT_MODEL'),
        'llm_vision_model' => env('AI_PTA_VISION_MODEL'),
        'llm_reasoning_model' => env('AI_PTA_REASONING_MODEL'),
        'llm_timeout' => (int) env('AI_PTA_LLM_TIMEOUT', 120),
        'llm_connect_timeout' => (int) env('AI_PTA_LLM_CONNECT_TIMEOUT', 5),
        'llm_retry_times' => (int) env('AI_PTA_LLM_RETRY_TIMES', 1),
        'llm_retry_sleep' => (int) env('AI_PTA_LLM_RETRY_SLEEP', 200),
        'llm_health_check_enabled' => (bool) env('AI_PTA_LLM_HEALTH_CHECK_ENABLED', true),
        'llm_allow_in_tests' => (bool) env('AI_PTA_LLM_ALLOW_IN_TESTS', false),
        'llm_max_chars' => (int) env('AI_PTA_LLM_MAX_CHARS', 60000),
        'windows_ocr_enabled' => (bool) env('AI_PTA_WINDOWS_OCR_ENABLED', true),
        'windows_ocr_script_path' => env('AI_PTA_WINDOWS_OCR_SCRIPT_PATH', base_path('scripts/ocr/windows_pdf_ocr.ps1')),
        'windows_ocr_max_pages' => (int) env('AI_PTA_WINDOWS_OCR_MAX_PAGES', 0),
        'windows_ocr_render_width' => (int) env('AI_PTA_WINDOWS_OCR_RENDER_WIDTH', 2600),
        'windows_ocr_timeout' => (int) env('AI_PTA_WINDOWS_OCR_TIMEOUT', 300),
        'linux_ocr_enabled' => (bool) env('AI_PTA_LINUX_OCR_ENABLED', true),
        'linux_ocr_script_path' => env('AI_PTA_LINUX_OCR_SCRIPT_PATH', base_path('scripts/ocr/linux_pdf_ocr.sh')),
        'linux_ocr_timeout' => (int) env('AI_PTA_LINUX_OCR_TIMEOUT', 900),
        'embedding_dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 64),
        'chunk_size' => (int) env('AI_KNOWLEDGE_CHUNK_SIZE', 1800),
    ],

    'reports' => [
        'pta_quarterly_template_path' => env(
            'AI_PTA_QUARTERLY_REPORT_TEMPLATE_PATH',
            env('AI_PTA_REPORT_TEMPLATE_PATH', base_path('docs/templates/rapport_pta_trimestriel_2026.docx'))
        ),
    ],
];
