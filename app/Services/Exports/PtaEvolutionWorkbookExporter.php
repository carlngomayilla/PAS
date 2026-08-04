<?php

namespace App\Services\Exports;

/**
 * Rapport d'evolution du PTA au format Excel.
 *
 * Reprend le modele institutionnel (support PAS/PAO/PTA de l'ANBG) : le rapport
 * est detaille par direction puis par service, chaque bloc etant precede du
 * responsable concerne. A l'interieur, chaque objectif operationnel forme un
 * bloc precede de son axe strategique et de son objectif strategique, suivi du
 * tableau des actions detaillees a dix colonnes.
 *
 * La plomberie XLSX est heritee de {@see PtaSuiviWorkbookExporter} ; seules la
 * composition des lignes et la palette changent (bleu #00B0F0 du modele).
 */
class PtaEvolutionWorkbookExporter extends PtaSuiviWorkbookExporter
{
    /** @var list<string> */
    public const COLUMNS = [
        'DESCRIPTION DES ACTIONS DÉTAILLÉES',
        'RMO',
        'CIBLE',
        'DÉBUT',
        'FIN',
        'ÉTAT DE RÉALISATION',
        'RESSOURCES REQUISES',
        'INDICATEURS DE PERFORMANCE',
        "TAUX D'EXÉCUTION",
        'RISQUES POTENTIELS',
    ];

    /** Bandeau bleu du modele institutionnel. */
    private const STYLE_BAND = 4;

    /** Ligne de libelle, sans remplissage. */
    private const STYLE_LABEL = 3;

    /** En-tete des colonnes. */
    private const STYLE_HEAD = 5;

    /** Entete de service. */
    private const STYLE_SERVICE = 6;

    protected function tempFilePrefix(): string
    {
        return 'pta_evolution_xlsx_';
    }

    protected function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="4">'
            .'<font><sz val="10"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="10"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            // fillId 2 : bleu du modele institutionnel.
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF00B0F0"/></patternFill></fill>'
            // fillId 3 : bleu tres clair pour l'entete de service.
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8F8FE"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="7">'
            // 0 : cellule courante
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            // 1 : titre du rapport
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 2 : sous-titre / perimetre
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            // 3 : ligne de libelle, sans remplissage
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>'
            // 4 : bandeau bleu
            .'<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            // 5 : en-tete des colonnes
            .'<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" wrapText="1"/></xf>'
            // 6 : entete de service
            .'<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{cells:list<string>,style:int}>
     */
    protected function rows(array $payload): array
    {
        $rows = [];
        $rows[] = ['cells' => [(string) ($payload['title'] ?? "RAPPORT D'ÉVOLUTION DU PTA")], 'style' => 1];
        $rows[] = ['cells' => [(string) ($payload['scopeLabel'] ?? '')], 'style' => 2];
        $rows[] = ['cells' => [], 'style' => 0];

        $directions = collect($payload['directions'] ?? []);

        if ($directions->isEmpty()) {
            $rows[] = ['cells' => ['Aucune action disponible pour les filtres actifs.'], 'style' => 2];

            return $rows;
        }

        foreach ($directions as $direction) {
            $direction = (array) $direction;

            $rows[] = ['cells' => ['DIRECTION : '.((string) ($direction['direction'] ?? '-'))], 'style' => self::STYLE_BAND];
            $rows[] = ['cells' => ['Directeur : '.((string) ($direction['directeur'] ?? 'Non renseigné'))], 'style' => self::STYLE_LABEL];

            foreach (collect($direction['services'] ?? []) as $service) {
                $service = (array) $service;

                $rows[] = ['cells' => ['SERVICE : '.((string) ($service['service'] ?? '-'))], 'style' => self::STYLE_SERVICE];
                $rows[] = ['cells' => ['Chef de service : '.((string) ($service['chef'] ?? 'Non renseigné'))], 'style' => self::STYLE_LABEL];

                foreach (collect($service['blocks'] ?? []) as $block) {
                    $block = (array) $block;

                    $rows[] = ['cells' => ['AXE STRATÉGIQUE'], 'style' => self::STYLE_LABEL];
                    $rows[] = ['cells' => [(string) ($block['axe'] ?? '-')], 'style' => self::STYLE_BAND];
                    $rows[] = ['cells' => ['OBJECTIF STRATÉGIQUE'], 'style' => self::STYLE_LABEL];
                    $rows[] = ['cells' => [(string) ($block['objectif_strategique'] ?? '-')], 'style' => self::STYLE_BAND];
                    $rows[] = ['cells' => ['OBJECTIF OPÉRATIONNEL N° '.((int) ($block['numero'] ?? 1))], 'style' => self::STYLE_LABEL];
                    $rows[] = ['cells' => [(string) ($block['objectif_operationnel'] ?? '-')], 'style' => self::STYLE_BAND];
                    $rows[] = ['cells' => self::COLUMNS, 'style' => self::STYLE_HEAD];

                    $actions = collect($block['actions'] ?? []);

                    if ($actions->isEmpty()) {
                        $rows[] = ['cells' => ['Aucune action rattachée à cet objectif opérationnel.'], 'style' => 0];
                    }

                    foreach ($actions as $actionRow) {
                        $rows[] = ['cells' => $this->evolutionCells((array) $actionRow), 'style' => 0];
                    }

                    $rows[] = ['cells' => [], 'style' => 0];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function evolutionCells(array $row): array
    {
        return [
            (string) ($row['libelle'] ?? '-'),
            (string) ($row['responsable'] ?? '-'),
            (string) ($row['cible'] ?? '-'),
            (string) ($row['debut_label'] ?? '-'),
            (string) ($row['fin_label'] ?? '-'),
            (string) ($row['statut_action_label'] ?? '-'),
            (string) ($row['ressources_requises'] ?? '-'),
            (string) ($row['livrable_attendu_label'] ?? '-'),
            (string) ($row['taux_realisation_label'] ?? '-'),
            (string) ($row['risques_potentiels'] ?? '-'),
        ];
    }
}
