<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKnowledgeChunk extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'chunk_index',
        'content',
        'metadata',
        'embedding',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'metadata' => 'array',
            'embedding' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeDocument::class, 'document_id');
    }
}
