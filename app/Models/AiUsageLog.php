<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'module',
        'operation_type',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'input_cost_usd',
        'output_cost_usd',
        'total_cost_usd',
        'request_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'input_cost_usd' => 'decimal:6',
            'output_cost_usd' => 'decimal:6',
            'total_cost_usd' => 'decimal:6',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_tokens' => 0,
        'input_cost_usd' => 0,
        'output_cost_usd' => 0,
        'total_cost_usd' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
