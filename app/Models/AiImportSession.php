<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiImportSession extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_REVIEW_REQUIRED = 'review_required';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'file_name',
        'original_file_path',
        'generated_excel_path',
        'file_type',
        'document_type',
        'status',
        'total_rows_detected',
        'total_rows_validated',
        'total_errors',
        'model_used',
        'input_tokens',
        'output_tokens',
        'total_cost_usd',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_rows_detected' => 'integer',
            'total_rows_validated' => 'integer',
            'total_errors' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_cost_usd' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => self::STATUS_UPLOADED,
        'total_rows_detected' => 0,
        'total_rows_validated' => 0,
        'total_errors' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_cost_usd' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AiImportRow::class, 'ai_import_session_id')->orderBy('source_line')->orderBy('id');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(AiImportError::class, 'ai_import_session_id')->latest('id');
    }

    public function importableRows(): HasMany
    {
        return $this->rows()->where('statut_import', AiImportRow::IMPORT_READY);
    }
}
