<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiKnowledgeDocument extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'type',
        'file_path',
        'content',
        'metadata',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiKnowledgeChunk::class, 'document_id')->orderBy('chunk_index');
    }
}
