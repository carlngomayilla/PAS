<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiImportRow extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_CORRECTED = 'corrected';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_IMPORTED = 'imported';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'batch_id',
        'row_number',
        'raw_payload',
        'normalized_payload',
        'validation_errors',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
            'validation_errors' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AiImportBatch::class, 'batch_id');
    }

    public function hasBlockingErrors(): bool
    {
        return $this->status === self::STATUS_INVALID
            && collect($this->validation_errors['errors'] ?? [])->isNotEmpty();
    }
}
