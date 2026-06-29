<?php

namespace App\Services\Ai;

class PtaQuarterlyNarrativeBuilder
{
    /**
     * @param  array<string, mixed>  $analysis
     * @return array{
     *     progression_globale:string,
     *     axes:list<string>,
     *     taux_axes:string,
     *     evolution_axes:string,
     *     taux_pta:string,
     *     evolution_pta:string,
     *     ecarts:list<string>,
     *     causes_ecarts:string,
     *     mesures_correctives_intro:string,
     *     mesures_correctives:list<string>
     * }
     */
    public function build(array $analysis): array
    {
        $summary = is_array($analysis['synthese'] ?? null) ? $analysis['synthese'] : [];
        $axes = is_array($analysis['axes'] ?? null) ? $analysis['axes'] : [];
        $services = is_array($analysis['services'] ?? null) ? $analysis['services'] : [];
        $monthly = is_array($analysis['evolution_mensuelle'] ?? null) ? $analysis['evolution_mensuelle'] : [];
        $gaps = is_array($analysis['ecarts'] ?? null) ? $analysis['ecarts'] : [];
        $measures = is_array($analysis['mesures_correctives'] ?? null) ? $analysis['mesures_correctives'] : [];

        return [
            'progression_globale' => $this->progressionGlobalParagraph($summary, $axes, $services),
            'axes' => $this->axisParagraphs($axes),
            'taux_axes' => $this->axisRateParagraph($axes),
            'evolution_axes' => $this->axisEvolutionParagraph($axes, $monthly),
            'taux_pta' => $this->ptaRateParagraph($services, $summary),
            'evolution_pta' => $this->monthlyEvolutionParagraph($monthly),
            'ecarts' => $this->gapParagraphs($gaps),
            'causes_ecarts' => $this->gapCausesParagraph($gaps, $axes, $services),
            'mesures_correctives_intro' => $this->correctiveIntroParagraph($gaps, $summary),
            'mesures_correctives' => $this->correctiveMeasures($measures, $gaps),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $axes
     * @param  list<array<string, mixed>>  $services
     */
    private function progressionGlobalParagraph(array $summary, array $axes, array $services): string
    {
        $actions = (int) ($summary['actions_prevues'] ?? 0);
        $completed = (int) ($summary['actions_realisees'] ?? 0);
        $late = (int) ($summary['actions_en_retard_non_realisees'] ?? 0);
        $notStarted = (int) ($summary['actions_non_demarrees'] ?? 0);
        $due = (int) ($summary['actions_echues'] ?? 0);
        $rate = (float) ($summary['taux_realisation'] ?? 0);
        $progress = (float) ($summary['taux_global_avancement'] ?? 0);

        return 'Il ressort de l analyse des donnees consolidees que le PTA de la Direction Generale couvre '
            .$this->countLabel($axes, 'axe strategique').' et '.$this->countLabel($services, 'PTA/service').'. '
            .'Sur '.$actions.' action(s) inscrite(s), '.$completed.' sont realisee(s), '.$late.' restent en retard ou non realisee(s), '
            .$notStarted.' ne sont pas encore demarree(s) et '.$due.' sont arrivee(s) a echeance. '
            .'Le taux global d avancement est de '.$this->asPercent($progress).', tandis que le taux de realisation des actions echues s etablit a '
            .$this->asPercent($rate).'. '.$this->globalReadingSentence($rate, $late, $notStarted);
    }

    /**
     * @param  list<array<string, mixed>>  $axes
     * @return list<string>
     */
    private function axisParagraphs(array $axes): array
    {
        if ($axes === []) {
            return ['Aucun axe strategique n est disponible dans le snapshot ; l analyse qualitative devra etre completee apres consolidation des donnees PTA.'];
        }

        return collect($axes)
            ->values()
            ->map(function (array $axis, int $index): string {
                $rate = (float) ($axis['taux_realisation'] ?? 0);
                $label = (string) ($axis['libelle'] ?? 'Non renseigne');
                $due = (int) ($axis['actions_echues'] ?? 0);
                $completed = (int) ($axis['actions_realisees'] ?? 0);
                $late = (int) ($axis['actions_en_retard_non_realisees'] ?? 0);

                return 'L axe strategique N '.($index + 1).' intitule "'.$label.'" presente '.$this->qualityLabel($rate)
                    .' avec '.$this->asPercent($rate).' de realisation. Il compte '.$due.' action(s) echue(s), '
                    .$completed.' action(s) realisee(s) et '.$late.' action(s) a suivre prioritairement. '
                    .$this->axisReadingSentence($rate, $late);
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $axes
     */
    private function axisRateParagraph(array $axes): string
    {
        if ($axes === []) {
            return 'Les taux par axe strategique ne sont pas encore disponibles ; le tableau de suivi doit etre complete pour permettre la comparaison.';
        }

        $best = $this->bestRow($axes);
        $weak = $this->weakestRow($axes);

        return 'Au regard des donnees consolidees, l analyse par axe permet de distinguer les domaines les plus avances de ceux qui necessitent un appui de suivi. '
            .'L axe le plus avance est "'.($best['libelle'] ?? 'Non renseigne').'" avec '.$this->asPercent($best['taux_realisation'] ?? 0)
            .', tandis que l axe le moins avance est "'.($weak['libelle'] ?? 'Non renseigne').'" avec '.$this->asPercent($weak['taux_realisation'] ?? 0)
            .'. Cette lecture doit guider la priorisation des relances et des arbitrages.';
    }

    /**
     * @param  list<array<string, mixed>>  $axes
     * @param  list<array<string, mixed>>  $monthly
     */
    private function axisEvolutionParagraph(array $axes, array $monthly): string
    {
        $axisText = collect($axes)
            ->map(fn (array $axis): string => ($axis['libelle'] ?? 'Non renseigne').' ('.$this->asPercent($axis['taux_realisation'] ?? 0).')')
            ->implode(', ');

        $trend = $this->monthlyTrendSentence($monthly);

        return 'L evolution des taux de realisation met en evidence une dynamique differenciee selon les axes. '
            .'Les taux disponibles sont : '.($axisText !== '' ? $axisText : 'donnee non disponible').'. '
            .$trend.' Les axes dont le taux reste faible doivent faire l objet d une revue ciblee avec les responsables concernes.';
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @param  array<string, mixed>  $summary
     */
    private function ptaRateParagraph(array $services, array $summary): string
    {
        if ($services === []) {
            return 'Aucun taux par PTA/service n est disponible ; la consolidation par structure reste donc a completer.';
        }

        $best = $this->bestRow($services);
        $weak = $this->weakestRow($services);

        return 'La lecture par PTA/service montre que le niveau de realisation global est de '.$this->asPercent($summary['taux_realisation'] ?? 0)
            .'. Le PTA/service le plus avance est "'.($best['libelle'] ?? 'Non renseigne').'" avec '.$this->asPercent($best['taux_realisation'] ?? 0)
            .', alors que "'.($weak['libelle'] ?? 'Non renseigne').'" constitue le point de vigilance principal avec '.$this->asPercent($weak['taux_realisation'] ?? 0)
            .'. Ces ecarts doivent etre analyses avec les responsables de mise en oeuvre afin de distinguer les retards administratifs, les difficultes de moyens et les actions simplement non mises a jour.';
    }

    /**
     * @param  list<array<string, mixed>>  $monthly
     */
    private function monthlyEvolutionParagraph(array $monthly): string
    {
        if ($monthly === []) {
            return 'L evolution mensuelle du PTA n est pas disponible dans le snapshot ; le suivi temporel devra etre complete lors de la prochaine consolidation.';
        }

        $first = collect($monthly)->first();
        $last = collect($monthly)->last();

        return 'Sur la periode observee, le suivi mensuel permet d apprecier la progression des actions arrivees a echeance. '
            .'Le taux passe de '.$this->asPercent($first['taux_realisation'] ?? 0).' en '.($first['mois'] ?? 'debut de periode')
            .' a '.$this->asPercent($last['taux_realisation'] ?? 0).' en '.($last['mois'] ?? 'fin de periode')
            .'. '.$this->monthlyTrendSentence($monthly);
    }

    /**
     * @param  array<string, mixed>  $gaps
     * @return list<string>
     */
    private function gapParagraphs(array $gaps): array
    {
        $late = $this->gapRows($gaps, 'actions_non_realisees');
        $partial = $this->gapRows($gaps, 'actions_partielles');
        $postponed = $this->gapRows($gaps, 'actions_reportees');

        return [
            'Les ecarts de realisation concernent principalement '.$this->countLabel($late, 'action non realisee').' arrivee(s) a echeance, '.$this->countLabel($partial, 'action partiellement realisee').' et '.$this->countLabel($postponed, 'activite reportee').'. Cette situation appelle une lecture operationnelle afin de separer les retards de traitement, les blocages de ressources et les arbitrages de calendrier.',
            $this->gapFocusSentence($late, $partial, $postponed),
        ];
    }

    /**
     * @param  array<string, mixed>  $gaps
     * @param  list<array<string, mixed>>  $axes
     * @param  list<array<string, mixed>>  $services
     */
    private function gapCausesParagraph(array $gaps, array $axes, array $services): string
    {
        $late = count($this->gapRows($gaps, 'actions_non_realisees'));
        $partial = count($this->gapRows($gaps, 'actions_partielles'));
        $postponed = count($this->gapRows($gaps, 'actions_reportees'));
        $lowRates = collect(array_merge($axes, $services))
            ->filter(fn (array $row): bool => (float) ($row['taux_realisation'] ?? 0) < 50)
            ->count();

        return 'Les causes probables des ecarts se situent a trois niveaux : la non-execution des actions echues ('.$late.' cas), '
            .'la progression partielle sans cloture formelle ('.$partial.' cas), et le report d activites vers une periode ulterieure ('.$postponed.' cas). '
            .'Le suivi doit egalement porter sur '.$lowRates.' axe(s) ou PTA/service(s) dont le taux est inferieur a 50 %, car ils peuvent peser sur la performance globale du trimestre.';
    }

    /**
     * @param  array<string, mixed>  $gaps
     * @param  array<string, mixed>  $summary
     */
    private function correctiveIntroParagraph(array $gaps, array $summary): string
    {
        $late = count($this->gapRows($gaps, 'actions_non_realisees'));

        return 'Les mesures correctives proposees visent a ameliorer le taux de realisation, securiser les actions echues et rendre le suivi plus regulier. '
            .'Compte tenu de '.$late.' action(s) echue(s) non realisee(s) et d un taux de realisation de '.$this->asPercent($summary['taux_realisation'] ?? 0)
            .', l effort doit porter en priorite sur les relances documentees, la clarification des responsables et la mise a jour des evidences de realisation.';
    }

    /**
     * @param  list<mixed>  $measures
     * @param  array<string, mixed>  $gaps
     * @return list<string>
     */
    private function correctiveMeasures(array $measures, array $gaps): array
    {
        $items = collect($measures)->map(fn (mixed $measure): string => (string) $measure)->filter()->values();

        if (count($this->gapRows($gaps, 'actions_non_realisees')) > 0) {
            $items->push('Mettre en place un point de suivi rapproche avec les RMO des actions echues non realisees, jusqu a regularisation ou arbitrage formel.');
        }

        if (count($this->gapRows($gaps, 'actions_partielles')) > 0) {
            $items->push('Demander pour chaque action partiellement realisee une preuve d avancement, le reste a faire et une date cible actualisee.');
        }

        return $items->unique()->values()->all();
    }

    private function globalReadingSentence(float $rate, int $late, int $notStarted): string
    {
        if ($rate >= 80 && $late === 0) {
            return 'Ce niveau traduit une execution globalement maitrisee, a consolider par la documentation des evidences de realisation.';
        }

        if ($rate >= 50) {
            return 'Ce niveau traduit une execution intermediaire : la dynamique existe, mais les actions en retard et non demarrees doivent etre traitees pour eviter une baisse du taux en fin de periode.';
        }

        return 'Ce niveau traduit une execution fragile, avec un besoin de relance immediate des responsables et de clarification des contraintes qui freinent la realisation.';
    }

    private function axisReadingSentence(float $rate, int $late): string
    {
        if ($rate >= 80 && $late === 0) {
            return 'La trajectoire de cet axe est favorable et doit etre maintenue par un suivi documentaire regulier.';
        }

        if ($rate >= 50) {
            return 'La trajectoire de cet axe reste perfectible et necessite un accompagnement cible des actions non cloturees.';
        }

        return 'La trajectoire de cet axe est critique pour la periode et doit etre priorisee dans les arbitrages de suivi.';
    }

    /**
     * @param  list<array<string, mixed>>  $monthly
     */
    private function monthlyTrendSentence(array $monthly): string
    {
        if (count($monthly) < 2) {
            return 'La tendance ne peut pas encore etre comparee faute de plusieurs mois renseignes.';
        }

        $first = collect($monthly)->first();
        $last = collect($monthly)->last();
        $delta = (float) ($last['taux_realisation'] ?? 0) - (float) ($first['taux_realisation'] ?? 0);

        if ($delta > 0) {
            return 'La tendance est positive, avec une hausse de '.$this->asPercent($delta).' sur la periode.';
        }

        if ($delta < 0) {
            return 'La tendance est en retrait, avec une baisse de '.$this->asPercent(abs($delta)).' sur la periode.';
        }

        return 'La tendance reste stable sur la periode, ce qui appelle un suivi rapproche pour provoquer une progression mesurable.';
    }

    /**
     * @param  list<array<string, mixed>>  $late
     * @param  list<array<string, mixed>>  $partial
     * @param  list<array<string, mixed>>  $postponed
     */
    private function gapFocusSentence(array $late, array $partial, array $postponed): string
    {
        if ($late !== []) {
            return 'Le premier point d attention porte sur les actions echues non realisees, notamment "'.($late[0]['libelle'] ?? 'Non renseigne').'", qui doit faire l objet d une decision de regularisation, de reprogrammation ou de justification.';
        }

        if ($partial !== []) {
            return 'Le premier point d attention porte sur les actions partiellement realisees, notamment "'.($partial[0]['libelle'] ?? 'Non renseigne').'", pour lesquelles le reste a faire doit etre precise.';
        }

        if ($postponed !== []) {
            return 'Le premier point d attention porte sur les activites reportees, notamment "'.($postponed[0]['libelle'] ?? 'Non renseigne').'", dont la nouvelle periode d execution doit etre confirmee.';
        }

        return 'Aucun ecart majeur n est remonte dans le snapshot ; la priorite est donc de maintenir la qualite du suivi et la mise a jour des preuves.';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function bestRow(array $rows): array
    {
        return collect($rows)->sortByDesc(fn (array $row): float => (float) ($row['taux_realisation'] ?? 0))->first() ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function weakestRow(array $rows): array
    {
        return collect($rows)->sortBy(fn (array $row): float => (float) ($row['taux_realisation'] ?? 0))->first() ?? [];
    }

    /**
     * @param  array<string, mixed>  $gaps
     * @return list<array<string, mixed>>
     */
    private function gapRows(array $gaps, string $key): array
    {
        return is_array($gaps[$key] ?? null) ? array_values($gaps[$key]) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function countLabel(array $rows, string $label): string
    {
        $count = count($rows);

        return $count.' '.$label.($count > 1 ? 's' : '');
    }

    private function qualityLabel(float $rate): string
    {
        return match (true) {
            $rate >= 80 => 'un niveau de realisation satisfaisant',
            $rate >= 50 => 'un niveau de realisation intermediaire',
            $rate > 0 => 'un niveau de realisation faible',
            default => 'un niveau de realisation non demarre',
        };
    }

    private function asPercent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').' %';
    }
}
