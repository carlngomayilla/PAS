<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionFollowupDocument extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'action_id',
        'justificatif_id',
        'version',
        'stage',
        'generated_by',
        'rmo_signature_receipt_id',
        'chef_signature_receipt_id',
        'planification_signature_receipt_id',
        'sciq_signature_receipt_id',
        'document_sha256',
        'generated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Une fiche de suivi générée est immuable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Une fiche de suivi ne peut pas être supprimée indépendamment.');
        });
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    public function justificatif(): BelongsTo
    {
        return $this->belongsTo(Justificatif::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function rmoSignatureReceipt(): BelongsTo
    {
        return $this->belongsTo(ActionSignatureReceipt::class, 'rmo_signature_receipt_id');
    }

    public function chefSignatureReceipt(): BelongsTo
    {
        return $this->belongsTo(ActionSignatureReceipt::class, 'chef_signature_receipt_id');
    }

    public function planificationSignatureReceipt(): BelongsTo
    {
        return $this->belongsTo(ActionSignatureReceipt::class, 'planification_signature_receipt_id');
    }

    public function sciqSignatureReceipt(): BelongsTo
    {
        return $this->belongsTo(ActionSignatureReceipt::class, 'sciq_signature_receipt_id');
    }
}
