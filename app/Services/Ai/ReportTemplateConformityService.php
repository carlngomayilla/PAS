<?php

namespace App\Services\Ai;

use App\Models\AiGeneratedReport;
use Illuminate\Support\Str;

class ReportTemplateConformityService
{
    /**
     * @return array{code:string,version:string,fingerprint:string,sections:list<array{key:string,title:string}>}
     */
    public function template(string $reportType): array
    {
        $sections = $reportType === AiGeneratedReport::TYPE_PTA_QUARTERLY
            ? [
                ['key' => 'progression_globale', 'title' => 'Progression globale du PTA de la Direction Generale'],
                ['key' => 'taux_axes', 'title' => 'Taux de realisation des axes strategiques'],
                ['key' => 'evolution_axes', 'title' => 'Evolution des taux de realisation des axes strategiques'],
                ['key' => 'taux_pta', 'title' => 'Taux de realisation du PTA de la Direction Generale'],
                ['key' => 'evolution_pta', 'title' => 'Evolution du taux de realisation du PTA'],
                ['key' => 'analyse_ecarts', 'title' => 'Analyse des ecarts constates'],
                ['key' => 'mesures_correctives', 'title' => 'Mesures correctives proposees'],
            ]
            : [
                ['key' => 'resume_executif', 'title' => 'Resume executif'],
                ['key' => 'methodologie', 'title' => 'Methodologie et perimetre'],
                ['key' => 'situation_globale', 'title' => 'Situation globale'],
                ['key' => 'analyse_performance', 'title' => 'Analyse des performances'],
                ['key' => 'ecarts_risques', 'title' => 'Ecarts, risques et alertes'],
                ['key' => 'recommandations', 'title' => 'Recommandations'],
                ['key' => 'conclusion', 'title' => 'Conclusion'],
            ];

        $code = $reportType === AiGeneratedReport::TYPE_PTA_QUARTERLY
            ? 'ANBG-PTA-TRIMESTRIEL'
            : 'ANBG-RAPPORT-INSTITUTIONNEL';
        $version = (string) config('ai_training.reports.template_version', '2026.1');

        return [
            'code' => $code,
            'version' => $version,
            'fingerprint' => hash('sha256', json_encode([$code, $version, $sections], JSON_THROW_ON_ERROR)),
            'sections' => $sections,
        ];
    }

    /**
     * @return array{status:string,score:int,issues:list<string>,template:array<string,mixed>}
     */
    public function inspect(string $reportType, string $content): array
    {
        $template = $this->template($reportType);
        $normalized = Str::of($content)->ascii()->lower()->squish()->toString();
        $positions = [];
        $issues = [];

        foreach ($template['sections'] as $section) {
            $marker = 'section:'.$section['key'];
            $position = mb_strpos($normalized, $marker);
            if ($position === false) {
                $issues[] = 'Section obligatoire absente : '.$section['title'].'.';

                continue;
            }

            $positions[] = $position;
        }

        if ($positions !== [] && $positions !== collect($positions)->sort()->values()->all()) {
            $issues[] = 'Les sections obligatoires ne respectent pas l ordre du modele.';
        }

        if (mb_strlen(trim($content)) < 600) {
            $issues[] = 'Le contenu est trop court pour constituer un rapport institutionnel exploitable.';
        }

        $score = max(0, 100 - (count($issues) * 20));

        return [
            'status' => $issues === [] ? AiGeneratedReport::CONFORMITY_CONFORMING : AiGeneratedReport::CONFORMITY_NON_CONFORMING,
            'score' => $issues === [] ? 100 : $score,
            'issues' => $issues,
            'template' => $template,
        ];
    }

    /**
     * @param  list<array{key:string,title:string,content:string}>  $sections
     */
    public function compose(string $title, array $sections): string
    {
        $content = ['# '.$title];

        foreach ($sections as $index => $section) {
            $content[] = '## '.($index + 1).'. '.$section['title'];
            $content[] = '[SECTION:'.$section['key'].']';
            $content[] = trim($section['content']);
        }

        return implode("\n\n", $content);
    }

    public function apply(AiGeneratedReport $report, ?string $content = null): AiGeneratedReport
    {
        $result = $this->inspect($report->report_type, $content ?? $report->contentForExport());
        $template = $result['template'];

        $report->forceFill([
            'template_code' => $template['code'],
            'template_version' => $template['version'],
            'template_fingerprint' => $template['fingerprint'],
            'conformity_status' => $result['status'],
            'conformity_score' => $result['score'],
            'conformity_issues' => $result['issues'],
            'conformity_checked_at' => now(),
        ])->save();

        return $report;
    }
}
