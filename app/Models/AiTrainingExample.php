<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTrainingExample extends Model
{
    public const TASK_PTA_EXTRACTION = 'pta_extraction';

    public const TASK_REPORT_WRITING = 'report_writing';

    public const TASK_CORRECTION = 'correction';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task',
        'input_text',
        'expected_json',
        'expected_text',
        'source',
        'is_validated',
        'validated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_json' => 'array',
            'is_validated' => 'boolean',
        ];
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
