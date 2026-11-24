<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionSignatureReceipt extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'action_id',
        'user_id',
        'stage',
        'actor_name',
        'actor_role',
        'actor_matricule',
        'signature_snapshot',
        'signature_sha256',
        'source_signature_updated_at',
        'signed_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'signature_snapshot',
        'signature_sha256',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signature_snapshot' => 'encrypted',
            'source_signature_updated_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Un reçu de signature est immuable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Un reçu de signature ne peut pas être supprimé indépendamment.');
        });
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
