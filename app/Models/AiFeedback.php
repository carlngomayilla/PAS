<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeedback extends Model
{
    public const MODULE_PTA_IMPORT = 'pta_import';

    public const MODULE_REPORT_GENERATION = 'report_generation';

    public const RATING_GOOD = 'good';

    public const RATING_BAD = 'bad';

    public const RATING_CORRECTED = 'corrected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'module',
        'rating',
        'ai_output',
        'human_correction',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
