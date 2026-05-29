<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeadlineExtensionRequest extends Model
{
    use HasFactory;

    public const STATUS_SOUMISE = 'soumise';
    public const STATUS_EN_ANALYSE = 'en_analyse';
    public const STATUS_COMPLEMENT_DEMANDE = 'complement_demande';
    public const STATUS_TRANSMISE_DG = 'transmise_dg';
    public const STATUS_APPROUVEE = 'approuvee';
    public const STATUS_REJETEE = 'rejetee';
    public const STATUS_MISE_A_JOUR_APPLIQUEE = 'mise_a_jour_appliquee';

    public const AVIS_FAVORABLE = 'avis_favorable';
    public const AVIS_DEFAVORABLE = 'avis_defavorable';
    public const AVIS_COMPLEMENT = 'demande_complement';

    public const DECISION_APPROUVER = 'approuver';
    public const DECISION_REJETER = 'rejeter';
    public const DECISION_COMPLEMENT = 'demander_complement';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action_id',
        'sous_action_id',
        'target_type',
        'old_deadline',
        'requested_deadline',
        'approved_deadline',
        'requested_by',
        'motif',
        'justification',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
        'is_critical',
        'status',
        'sciq_avis',
        'sciq_comment',
        'sciq_reviewed_by',
        'sciq_reviewed_at',
        'dg_decision',
        'dg_comment',
        'dg_decided_by',
        'dg_decided_at',
        'applied_by',
        'applied_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_deadline' => 'date',
            'requested_deadline' => 'date',
            'approved_deadline' => 'date',
            'is_critical' => 'boolean',
            'sciq_reviewed_at' => 'datetime',
            'dg_decided_at' => 'datetime',
            'applied_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'action_id');
    }

    public function sousAction(): BelongsTo
    {
        return $this->belongsTo(SousAction::class, 'sous_action_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function sciqReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sciq_reviewed_by');
    }

    public function dgDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dg_decided_by');
    }
}
