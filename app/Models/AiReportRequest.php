<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiReportRequest extends Model
{
    public const TYPE_MONTHLY = 'monthly';

    public const TYPE_QUARTERLY = 'quarterly';

    public const TYPE_ANNUAL = 'annual';

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'report_type',
        'period_start',
        'period_end',
        'direction_id',
        'service_id',
        'status',
        'model_used',
        'input_tokens',
        'output_tokens',
        'total_cost_usd',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_cost_usd' => 'decimal:6',
        ];
    }

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_cost_usd' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function report(): HasOne
    {
        return $this->hasOne(AiReport::class, 'ai_report_request_id');
    }
}
