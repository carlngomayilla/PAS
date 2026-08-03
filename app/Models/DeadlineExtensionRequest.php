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

    public const STATUS_TRANSMISE_CONTROLE = 'transmise_controle';

    public const STATUS_TRANSMISE_DIRECTION = 'transmise_direction';

    public const STATUS_TRANSMISE_VALIDATION_FINALE = 'transmise_validation_finale';

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
        'requested_changes',
        'original_values',
        'applied_values',
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
        'chef_avis',
        'chef_comment',
        'chef_reviewed_by',
        'chef_reviewed_at',
        'director_decision',
        'director_comment',
        'director_reviewed_by',
        'director_reviewed_at',
        'sciq_avis',
        'sciq_comment',
        'sciq_reviewed_by',
        'sciq_reviewed_at',
        'final_decision',
        'final_comment',
        'final_decided_by',
        'final_decided_at',
        'final_approver_role',
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
            'requested_changes' => 'array',
            'original_values' => 'array',
            'applied_values' => 'array',
            'is_critical' => 'boolean',
            'chef_reviewed_at' => 'datetime',
            'director_reviewed_at' => 'datetime',
            'sciq_reviewed_at' => 'datetime',
            'final_decided_at' => 'datetime',
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

    public function chefReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chef_reviewed_by');
    }

    public function directorReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'director_reviewed_by');
    }

    public function finalDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_decided_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function dgDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dg_decided_by');
    }
}
