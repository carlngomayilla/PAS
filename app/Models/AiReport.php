<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiReport extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'ai_report_request_id',
        'title',
        'summary',
        'report_html',
        'report_json',
        'pdf_path',
        'word_path',
        'excel_path',
        'generated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_json' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiReportRequest::class, 'ai_report_request_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(AiReportSection::class, 'ai_report_id')->orderBy('section_order');
    }
}
