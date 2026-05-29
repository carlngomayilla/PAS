<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanningImport extends Model
{
    use HasFactory;

    public const MODE_CREATE_ONLY = 'create_only';
    public const MODE_SKIP_DUPLICATES = 'skip_duplicates';
    public const MODE_UPDATE_EXISTING = 'update_existing';

    protected $fillable = [
        'user_id',
        'role',
        'filename',
        'module',
        'mode',
        'total_rows',
        'valid_rows',
        'error_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'status',
        'error_report',
        'preview_payload',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'error_report' => 'array',
            'preview_payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
