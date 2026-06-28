<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PtaImportMapping extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'batch_id',
        'source_column',
        'target_field',
        'confidence_score',
        'is_confirmed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence_score' => 'decimal:2',
            'is_confirmed' => 'boolean',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AiImportBatch::class, 'batch_id');
    }
}
