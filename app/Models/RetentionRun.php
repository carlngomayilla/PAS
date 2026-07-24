<?php

namespace App\Models;

use Database\Factories\RetentionRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetentionRun extends Model
{
    /** @use HasFactory<RetentionRunFactory> */
    use HasFactory;

    public const SCOPE_DATA = 'data';

    public const SCOPE_PLANNING = 'planning';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_EXECUTE = 'execute';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'scope',
        'mode',
        'status',
        'source',
        'initiated_by',
        'batch_key',
        'candidates',
        'processed',
        'error_message',
        'started_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'candidates' => 'array',
            'processed' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
