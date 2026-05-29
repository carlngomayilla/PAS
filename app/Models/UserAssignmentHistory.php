<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAssignmentHistory extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'changed_by',
        'previous_role',
        'new_role',
        'previous_custom_role_code',
        'new_custom_role_code',
        'previous_direction_id',
        'new_direction_id',
        'previous_service_id',
        'new_service_id',
        'previous_unite_dg_id',
        'new_unite_dg_id',
        'transfer_to_user_id',
        'open_assignments_before',
        'transfer_summary',
        'reason',
        'changed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'open_assignments_before' => 'array',
            'transfer_summary' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transfer_to_user_id');
    }
}
