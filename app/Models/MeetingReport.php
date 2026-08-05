<?php

namespace App\Models;

use App\Enums\MeetingApprovalDecision;
use App\Enums\MeetingApprovalLevel;
use App\Enums\MeetingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Version d'un proces-verbal. Une correction cree une nouvelle version ;
 * l'ancienne est conservee.
 */
class MeetingReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_id',
        'file_path',
        'original_file_name',
        'file_size',
        'mime_type',
        'checksum',
        'version',
        'status',
        'observation',
        'uploaded_by',
        'uploaded_at',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MeetingStatus::class,
            'version' => 'integer',
            'file_size' => 'integer',
            'uploaded_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(MeetingApproval::class)->orderBy('reviewed_at');
    }

    /** Un PV definitivement valide est verrouille : ni modification ni suppression. */
    public function isLocked(): bool
    {
        return $this->locked_at !== null
            || $this->status === MeetingStatus::ValideeDefinitivement;
    }

    /** Le niveau demande a-t-il deja valide cette version ? */
    public function hasValidationFrom(MeetingApprovalLevel $level): bool
    {
        return $this->approvals
            ->contains(fn (MeetingApproval $approval): bool => $approval->approval_level === $level
                && $approval->decision === MeetingApprovalDecision::Validated);
    }

    /** Prochain niveau attendu, ou null si le circuit est termine. */
    public function pendingLevel(): ?MeetingApprovalLevel
    {
        return match ($this->status) {
            MeetingStatus::EnValidationSciq => MeetingApprovalLevel::Sciq,
            MeetingStatus::EnValidationPlanification => MeetingApprovalLevel::Planification,
            default => null,
        };
    }

    public function humanSize(): string
    {
        $size = (float) $this->file_size;
        if ($size <= 0) {
            return '-';
        }

        $units = ['o', 'Ko', 'Mo', 'Go'];
        $index = 0;
        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, $index === 0 ? 0 : 1).' '.$units[$index];
    }
}
