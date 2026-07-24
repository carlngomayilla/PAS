<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiImportRow extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_CORRECTED = 'corrected';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_IMPORTED = 'imported';

    public const IMPORT_READY = 'pret_a_importer';

    public const IMPORT_VERIFY = 'a_verifier';

    public const IMPORT_PARAMETERIZE = 'a_parametrer';

    public const IMPORT_VALIDATE = 'a_valider';

    public const IMPORT_DATE_ERROR = 'erreur_date';

    public const IMPORT_ATTACHMENT_ERROR = 'erreur_rattachement';

    public const IMPORT_POSSIBLE_DUPLICATE = 'doublon_possible';

    public const IMPORT_REJECTED = 'rejetee';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ai_import_session_id',
        'batch_id',
        'row_number',
        'raw_payload',
        'normalized_payload',
        'validation_errors',
        'status',
        'source_page',
        'source_line',
        'code_pas',
        'axe',
        'objectif_strategique',
        'objectif_operationnel',
        'direction',
        'service',
        'action',
        'sous_action',
        'rmo',
        'cible',
        'type_indicateur',
        'quantite_a_realiser',
        'livrable_attendu',
        'unite_mesure',
        'date_debut',
        'date_fin',
        'statut_import',
        'errors_json',
        'raw_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
            'validation_errors' => 'array',
            'source_page' => 'integer',
            'source_line' => 'integer',
            'quantite_a_realiser' => 'decimal:4',
            'date_debut' => 'date',
            'date_fin' => 'date',
            'errors_json' => 'array',
            'raw_json' => 'array',
        ];
    }

    protected $attributes = [
        'statut_import' => self::IMPORT_VERIFY,
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AiImportBatch::class, 'batch_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiImportSession::class, 'ai_import_session_id');
    }

    public function importErrors(): HasMany
    {
        return $this->hasMany(AiImportError::class, 'ai_import_row_id');
    }

    public function hasBlockingErrors(): bool
    {
        return $this->status === self::STATUS_INVALID
            && collect($this->validation_errors['errors'] ?? [])->isNotEmpty();
    }
}
