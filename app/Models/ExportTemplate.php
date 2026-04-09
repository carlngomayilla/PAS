<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExportTemplate extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const FORMAT_PDF = 'pdf';
    public const FORMAT_EXCEL = 'excel';
    public const FORMAT_WORD = 'word';
    public const FORMAT_CSV = 'csv';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'format',
        'module',
        'report_type',
        'target_profile',
        'reading_level',
        'status',
        'is_default',
        'is_active',
        'blocks_config',
        'layout_config',
        'content_config',
        'style_config',
        'meta_config',
        'created_by',
        'updated_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'blocks_config' => 'array',
            'layout_config' => 'array',
            'content_config' => 'array',
            'style_config' => 'array',
            'meta_config' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ExportTemplateVersion::class)->orderByDesc('version_number');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ExportTemplateAssignment::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->is_active;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PUBLISHED => 'Publie',
            self::STATUS_ARCHIVED => 'Archive',
            default => 'Brouillon',
        };
    }

    public function formatLabel(): string
    {
        return match ($this->format) {
            self::FORMAT_PDF => 'PDF',
            self::FORMAT_EXCEL => 'Excel',
            self::FORMAT_WORD => 'Word',
            self::FORMAT_CSV => 'CSV',
            default => strtoupper((string) $this->format),
        };
    }

    public function documentTitle(): string
    {
        return (string) ($this->meta_config['document_title'] ?? $this->name);
    }

    public function documentSubtitle(): ?string
    {
        $subtitle = trim((string) ($this->meta_config['document_subtitle'] ?? ''));

        return $subtitle !== '' ? $subtitle : null;
    }

    public function filenamePrefix(): string
    {
        $prefix = trim((string) ($this->meta_config['filename_prefix'] ?? ''));

        return $prefix !== '' ? $prefix : 'reporting_anbg';
    }

    public function paperSize(): string
    {
        return (string) ($this->layout_config['paper_size'] ?? 'a4');
    }

    public function orientation(): string
    {
        return (string) ($this->layout_config['orientation'] ?? 'landscape');
    }

    /**
     * @return array<int, string>
     */
    public static function formatOptions(): array
    {
        return [self::FORMAT_PDF, self::FORMAT_EXCEL, self::FORMAT_WORD, self::FORMAT_CSV];
    }

    /**
     * @return array<int, string>
     */
    public static function statusOptions(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];
    }

    /**
     * @return list<string>
     */
    public static function allowedDynamicVariables(): array
    {
        return [
            '{app_name}',
            '{report_title}',
            '{report_subtitle}',
            '{period}',
            '{generated_at}',
            '{generated_by}',
            '{direction_name}',
            '{service_name}',
            '{validation_status}',
            '{official_badge}',
            '{kpi_table}',
            '{actions_table}',
            '{alerts_table}',
            '{signature_block}',
        ];
    }
}
