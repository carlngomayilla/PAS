<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiImportBatch extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_EXTRACTED = 'extracted';

    public const STATUS_MAPPED = 'mapped';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'original_filename',
        'file_path',
        'file_type',
        'status',
        'detected_year',
        'detected_direction',
        'detected_service',
        'confidence_score',
        'generated_excel_path',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detected_year' => 'integer',
            'confidence_score' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AiImportRow::class, 'batch_id')->orderBy('row_number');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(PtaImportMapping::class, 'batch_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AiImportAudit::class, 'batch_id')->latest('created_at');
    }

    public function blockingRows(): HasMany
    {
        return $this->rows()->where('status', AiImportRow::STATUS_INVALID);
    }

    public function importableRows(): HasMany
    {
        return $this->rows()->whereIn('status', [
            AiImportRow::STATUS_VALID,
            AiImportRow::STATUS_CORRECTED,
        ]);
    }
}
