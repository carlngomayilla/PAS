<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalAudit extends Model
{
    use HasFactory;

    protected $table = 'journal_audit';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'module',
        'entite_type',
        'entite_id',
        'action',
        'ancienne_valeur',
        'nouvelle_valeur',
        'adresse_ip',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ancienne_valeur' => 'array',
            'nouvelle_valeur' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
