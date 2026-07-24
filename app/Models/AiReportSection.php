<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiReportSection extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'ai_report_id',
        'section_title',
        'section_order',
        'content',
        'indicators_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section_order' => 'integer',
            'indicators_json' => 'array',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(AiReport::class, 'ai_report_id');
    }
}
