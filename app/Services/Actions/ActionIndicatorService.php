<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\Kpi;

class ActionIndicatorService
{
    /**
     * @var list<string>
     */
    public const PERIODICITY_OPTIONS = [
        'mensuel',
        'trimestriel',
        'semestriel',
        'annuel',
        'ponctuel',
    ];

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    public function pullPrimaryIndicatorPayload(array &$validated): array
    {
        $payload = [
            'libelle' => trim((string) ($validated['kpi_libelle'] ?? '')),
            'unite' => $this->nullableString($validated['kpi_unite'] ?? null),
            'cible' => $this->nullableDecimal($validated['kpi_cible'] ?? null),
            'seuil_alerte' => $this->nullableDecimal($validated['kpi_seuil_alerte'] ?? null),
            'periodicite' => in_array((string) ($validated['kpi_periodicite'] ?? ''), self::PERIODICITY_OPTIONS, true)
                ? (string) $validated['kpi_periodicite']
                : 'mensuel',
            'est_a_renseigner' => array_key_exists('kpi_est_a_renseigner', $validated)
                ? (bool) $validated['kpi_est_a_renseigner']
                : true,
        ];

        unset(
            $validated['kpi_libelle'],
            $validated['kpi_unite'],
            $validated['kpi_cible'],
            $validated['kpi_seuil_alerte'],
            $validated['kpi_periodicite'],
            $validated['kpi_est_a_renseigner']
        );

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function syncPrimaryIndicator(Action $action, array $payload): Kpi
    {
        $indicator = $action->primaryKpi()->first();
        if (! $indicator instanceof Kpi) {
            $indicator = new Kpi();
            $indicator->action()->associate($action);
        }

        if (($payload['unite'] ?? null) === null) {
            if ($action->type_cible === 'quantitative') {
                $payload['unite'] = $this->nullableString($action->unite_cible);
            } else {
                $payload['unite'] = $this->nullableString($indicator->unite);
            }
        }

        if (($payload['cible'] ?? null) === null) {
            if ($action->type_cible === 'quantitative' && $action->quantite_cible !== null) {
                $payload['cible'] = (float) $action->quantite_cible;
            } else {
                $payload['cible'] = $this->nullableDecimal($indicator->cible);
            }
        }

        if (trim((string) ($payload['libelle'] ?? '')) === '') {
            $payload['libelle'] = trim((string) $action->libelle);
        }

        $indicator->fill($payload);
        $indicator->save();

        return $indicator->refresh();
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function nullableDecimal(mixed $value): float|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
