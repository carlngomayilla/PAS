<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGeneratedReport extends Model
{
    public const TYPE_PAS_GLOBAL = 'pas_global';

    public const TYPE_PAO_DIRECTION = 'pao_direction';

    public const TYPE_PTA_ANNUAL = 'pta_annual';

    public const TYPE_PTA_QUARTERLY = 'pta_quarterly';

    public const TYPE_EXECUTION_MONTHLY = 'execution_monthly';

    public const TYPE_LATE_ACTIONS = 'late_actions';

    public const TYPE_RUNNING_ACTIONS = 'running_actions';

    public const TYPE_CLOSED_ACTIONS = 'closed_actions';

    public const TYPE_PERFORMANCE_SCOPE = 'performance_scope';

    public const TYPE_CONTROL_VALIDATION = 'control_validation';

    public const TYPE_ALERTS_DEADLINES = 'alerts_deadlines';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_EXPORTED = 'exported';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'report_type',
        'title',
        'period_start',
        'period_end',
        'filters',
        'metrics_snapshot',
        'ai_draft',
        'validated_content',
        'status',
        'exported_pdf_path',
        'exported_docx_path',
        'exported_xlsx_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'filters' => 'array',
            'metrics_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    public static function reportTypes(): array
    {
        return [
            self::TYPE_PAS_GLOBAL => 'Rapport global de suivi PAS',
            self::TYPE_PAO_DIRECTION => 'Rapport de suivi PAO par direction',
            self::TYPE_PTA_ANNUAL => 'Rapport annuel PTA',
            self::TYPE_PTA_QUARTERLY => 'Rapport trimestriel PTA',
            self::TYPE_EXECUTION_MONTHLY => 'Rapport mensuel d execution',
            self::TYPE_LATE_ACTIONS => 'Rapport des actions hors delai',
            self::TYPE_RUNNING_ACTIONS => 'Rapport des actions en cours',
            self::TYPE_CLOSED_ACTIONS => 'Rapport des actions cloturees',
            self::TYPE_PERFORMANCE_SCOPE => 'Rapport des performances directions/services',
            self::TYPE_CONTROL_VALIDATION => 'Rapport de controle et validation',
            self::TYPE_ALERTS_DEADLINES => 'Rapport des alertes et echeances critiques',
        ];
    }

    public function contentForExport(): string
    {
        return trim((string) ($this->validated_content ?: $this->ai_draft));
    }
}
