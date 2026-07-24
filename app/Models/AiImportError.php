<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiImportError extends Model
{
    public const GRAVITY_BLOCKING = 'bloquant';

    public const GRAVITY_WARNING = 'avertissement';

    public const GRAVITY_INFO = 'information';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ai_import_session_id',
        'ai_import_row_id',
        'gravity',
        'field',
        'message',
        'suggestion',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiImportSession::class, 'ai_import_session_id');
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(AiImportRow::class, 'ai_import_row_id');
    }
}
