<?php

namespace Database\Seeders;

use App\Models\ExportTemplate;
use App\Models\User;
use App\Services\Exports\ExportTemplatePublisher;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->where('email', 'superadmin@anbg.ga')->first();
        $actorId = $actor?->id;

        $templates = [
            [
                'code' => 'reporting-pdf-officiel-default',
                'name' => 'Reporting PDF institutionnel',
                'description' => 'Template PDF par defaut pour le reporting consolide.',
                'format' => ExportTemplate::FORMAT_PDF,
                'module' => 'reporting',
                'report_type' => 'consolidated_reporting',
                'reading_level' => 'officiel',
                'meta_config' => ['document_title' => 'Reporting consolide ANBG', 'document_subtitle' => 'Version institutionnelle officielle', 'filename_prefix' => 'reporting_anbg'],
                'layout_config' => ['paper_size' => 'a4', 'orientation' => 'landscape', 'header_text' => 'ANBG - Reporting institutionnel', 'footer_text' => 'Document officiel', 'watermark_text' => 'OFFICIEL'],
            ],
            [
                'code' => 'reporting-excel-officiel-default',
                'name' => 'Reporting Excel consolide',
                'description' => 'Template Excel par defaut pour le reporting consolide.',
                'format' => ExportTemplate::FORMAT_EXCEL,
                'module' => 'reporting',
                'report_type' => 'consolidated_reporting',
                'reading_level' => 'officiel',
                'meta_config' => ['document_title' => 'Reporting consolide ANBG', 'document_subtitle' => 'Classeur de diffusion institutionnelle', 'filename_prefix' => 'reporting_anbg'],
                'layout_config' => ['paper_size' => 'a4', 'orientation' => 'landscape', 'header_text' => 'ANBG - Reporting consolide', 'footer_text' => 'Synthese multi-feuilles', 'watermark_text' => ''],
            ],
        ];

        foreach ($templates as $data) {
            $template = ExportTemplate::query()->updateOrCreate(
                ['code' => $data['code']],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'format' => $data['format'],
                    'module' => $data['module'],
                    'report_type' => $data['report_type'],
                    'target_profile' => null,
                    'reading_level' => $data['reading_level'],
                    'status' => ExportTemplate::STATUS_PUBLISHED,
                    'is_default' => true,
                    'is_active' => true,
                    'blocks_config' => ['include_cover' => true, 'include_summary' => true, 'include_detail_table' => true, 'include_charts' => true, 'include_alerts' => true, 'include_signatures' => false],
                    'layout_config' => $data['layout_config'],
                    'content_config' => ['visible_columns' => ['libelle', 'statut', 'validation', 'kpi_global'], 'dynamic_variables' => ['{app_name}', '{report_title}', '{generated_at}']],
                    'style_config' => ['color_primary' => '#1E3A8A', 'color_secondary' => '#3B82F6', 'font_family' => 'Inter'],
                    'meta_config' => $data['meta_config'],
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'published_at' => now(),
                ]
            );

            $template->assignments()->updateOrCreate(
                ['module' => $template->module, 'report_type' => $template->report_type, 'format' => $template->format, 'target_profile' => null, 'reading_level' => $template->reading_level, 'direction_id' => null, 'service_id' => null],
                ['is_default' => true, 'is_active' => true, 'created_by' => $actorId, 'updated_by' => $actorId]
            );

            if ($actor instanceof User && ! $template->versions()->exists()) {
                app(ExportTemplatePublisher::class)->publish($template, $actor, 'Publication initiale seedee.');
            }
        }
    }
}
